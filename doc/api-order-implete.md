# 订单系统 API 对接文档

> 基于当前系统代码（`routes/api.php`、`ApiAuthentication` 中间件、`Api\OrderController`、`OrderNotificationService`）整理，供外部系统对接使用。文档里的 `{BASE_URL}` 请替换为实际部署域名。

## 目录

1. [对接流程总览](#对接流程总览)
2. [鉴权与签名（三个接口通用）](#鉴权与签名三个接口通用)
3. [POST /order/create（创建订单）](#post-ordercreate创建订单)
4. [POST /order/ship（同步物流信息）](#post-ordership同步物流信息)
5. [POST /order/query（查询订单）](#post-orderquery查询订单)
6. [Webhook：交易结果通知](#webhook交易结果通知)
7. [订单状态枚举](#订单状态枚举)
8. [错误码汇总](#错误码汇总)
9. [对接前需要准备的信息](#对接前需要准备的信息)
10. [常见对接问题](#常见对接问题)

---

## 对接流程总览

```
外部系统                                          订单系统
────────                                        ────────
1. POST /order/create  ───────────────────────►  创建订单，锁定支付方式
   ◄──────────────────────────────────────────  返回 payment_url
2. 引导用户跳转 payment_url  ───────────────────►  托管收银页，用户完成支付
3. 支付结果产生
   ◄──── POST notify_url（webhook，仅 paid）────  交易结果异步通知
4. 用户浏览器跳回 return_url / cancel_url（仅展示，不可作为状态依据）
5. 兜底：POST /order/query 主动查询订单最终状态
6. 发货后：POST /order/ship  ───────────────────►  同步物流单号（不产生 webhook，见第 6 节说明）
```

**核心原则**：订单状态以 webhook 通知 + 主动查询为准，`return_url` 跳转只是用户体验展示，不可信。

---

## 鉴权与签名（三个接口通用）

`/order/create`、`/order/query`、`/order/ship` 三个接口都走同一套 App-ID + 签名鉴权（`ApiAuthentication` 中间件）。

**这套算法和第 6 节"Webhook 交易结果通知"的验签算法完全一致**，只是方向相反：你调用我们的接口时，你按下面的规则计算 `sign` 放进请求；我们推 webhook 给你时，我们按同样的规则计算 `sign` 放进请求。也就是说你只需要实现**一个**"计算/校验签名"的函数，两个方向直接复用，不用维护两套逻辑。

### Header（3 个，缺一不可）

| Header | 说明 |
|---|---|
| `App-ID` | 应用的 `app_id`（系统后台"应用管理"创建应用后生成） |
| `Timestamp` | 当前 Unix 时间戳（秒级），允许 ±5 分钟误差，超过视为过期请求 |
| `X-Nonce` | 随机字符串，防重放。5 分钟内不可重复使用（服务端用 Cache 记录已用过的 Nonce） |

`Content-Type` 建议固定传 `application/json`。

### 签名放在请求体的 `sign` 字段（不是 Header）

```
StringToSign = App-ID + "\n" + Timestamp + "\n" + X-Nonce + "\n" + 规范化请求体（不含 sign）
Signature    = HMAC-SHA256(StringToSign, api_key)   // 小写十六进制
```

**规范化请求体**是唯一的技术难点，因为 `sign` 字段本身在请求体里，必须双方约定同一套规则：

1. 从请求体里**移除** `sign` 字段。
2. 对所有**关联数组（对象）**按 key 做**递归排序**（`ksort`）；**数字索引的列表**（如 `items`）**保持原顺序**，不做排序。
3. 用规范化后的结果 `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` 编码成字符串（不转义中文、不转义斜杠）。

> ⚠️ 这一步最容易出错。签名对不上时服务端只会返回笼统的"签名验证失败"，不会告诉你具体哪个环节错了。建议先用一两个字段的最简请求体调通签名逻辑，再接入完整流程。

### 参考实现（PHP）

```php
function ksortRecursive($value) {
    if (! is_array($value)) {
        return $value;
    }
    $isList = array_is_list($value);
    $result = [];
    foreach ($value as $k => $v) {
        $result[$k] = ksortRecursive($v);
    }
    if (! $isList) {
        ksort($result);
    }
    return $result;
}

function canonicalize(array $body): string {
    unset($body['sign']);
    return json_encode(ksortRecursive($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$appId = 'APP_XXXXXXXXXXXX';
$apiKey = 'your_api_key';
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(8));

$body = [ /* 见各接口的请求体字段 */ ];

$sign = hash_hmac('sha256',
    $appId . "\n" . $timestamp . "\n" . $nonce . "\n" . canonicalize($body),
    $apiKey
);
$body['sign'] = $sign; // 计算完再把 sign 加回请求体

$ch = curl_init('{BASE_URL}/api/order/create');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'App-ID: ' . $appId,
    'Timestamp: ' . $timestamp,
    'X-Nonce: ' . $nonce,
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
```

> 金额类字段一律以**字符串**传递（如 `"190.00"` 而不是 `190.00`），避免不同语言/客户端的数字序列化差异导致签名或金额校验对不上。

### 鉴权失败统一返回 401

```json
{ "code": 401, "msg": "Signature verification failed." }
```

触发条件：`App-ID`/`Timestamp`/`X-Nonce` 缺失、时间戳误差超过 5 分钟、Nonce 重复使用、App-ID 不存在或已禁用、签名校验失败。

---

## POST /order/create（创建订单）

```
POST {BASE_URL}/api/order/create
```

商户系统调用此接口创建一笔订单并锁定支付方式。

### 请求体

```json
{
  "merchant_order_no": "M20260706001",
  "platform": "wordpress",
  "currency": "EUR",
  "group_key": "group_default",
  "subtotal": "180.00",
  "shipping_fee": "15.00",
  "discount": "10.00",
  "tax": "5.00",
  "amount": "190.00",
  "customer": {
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@example.com",
    "phone": "+4915112345678"
  },
  "shipping_address": {
    "line1": "Alexanderplatz 1",
    "line2": "Apt. 4B",
    "city": "Berlin",
    "state": "Berlin",
    "country": "DE",
    "zip": "10178"
  },
  "items": [
    {
      "product_sku": "SKU-1001",
      "product_id": "1",
      "product_url": "https://merchant.example.com/product/mechanical-keyboard",
      "product_name": "Mechanical Keyboard",
      "product_description": "Blue switches, RGB backlight",
      "unit_price": "90.00",
      "quantity": 2
    }
  ],
  "notify_url": "https://merchant.example.com/api/payment/callback",
  "return_url": "https://merchant.example.com/order/success",
  "cancel_url": "https://merchant.example.com/order/cancel",
  "sign": "…"
}
```

### 字段说明

| 字段 | 类型 | 必填 | 说明                                                                                         |
|---|---|---|--------------------------------------------------------------------------------------------|
| `merchant_order_no` | string ≤64 | ✅ | 商户自己的订单号，是**幂等键**（与商户账号组合唯一）。重复提交原样返回已存在的订单，不会重复创建、不会重新算汇率或重新走风控                           |
| `platform` | string ≤50 | ✅ | 电商网站平台类型枚举，合法值列表由系统配置 `order.platforms` 动态维护，不在列表内直接拒单                                     |
| `currency` | string(3) | ✅ | 下单币种（如 `EUR`/`JPY`/`GBP`），须在系统支持的币种列表内                                                     |
| `group_key` | string ≤50 | ✅ | 支付方式组标识（系统后台"支付组"配置）。即使传了 `payment_method_key` 也仍然必填                                       |
| `payment_method_key` | string ≤50 | 否 | **指定支付渠道**（取值为后台"支付方式"的 `method_code`）。传了会跳过支付组路由与限额风控，直接用该渠道；代价是三个回跳地址全部必填且域名必须与该渠道绑定站点一致 |
| `subtotal` | string(数字) | ✅ | 商品小计，须等于 `items[].unit_price × quantity` 之和                                                |
| `shipping_fee` | string(数字) | ✅ | 运费                                                                                         |
| `discount` | string(数字) | ✅ | 折扣（正数表示减免）                                                                                 |
| `tax` | string(数字) | ✅ | 税金                                                                                         |
| `amount` | string(数字) | ✅ | 应付总额，必须严格等于 `subtotal + shipping_fee - discount + tax`，误差超过 0.01 直接拒单                      |
| `customer.first_name`/`last_name`/`email`/`phone` | string | ✅ | 客户信息                                                                                       |
| `shipping_address.line1` | string ≤255 | ✅ | 地址行 1                                                                                      |
| `shipping_address.line2` | string ≤255 | 否 | 地址行 2                                                                                      |
| `shipping_address.city` | string ≤100 | ✅ | 城市                                                                                         |
| `shipping_address.state` | string ≤100 | 否 | 州/省                                                                                        |
| `shipping_address.country` | string(2) | ✅ | ISO 3166-1 alpha-2（如 `DE`、`US`）                                                            |
| `shipping_address.zip` | string ≤20 | ✅ | 邮编                                                                                         |
| `items` | array ≥1 | ✅ | 商品明细                                                                                       |
| `items[].product_sku` | string ≤64 | 否 | 商户侧商品编号                                                                                    |
| `items[].product_id` | string ≤64 | ✅ | 商户侧商品唯一标识,wordpress 传入 商品 的product_id                                                      |
| `items[].product_url` | url ≤500 | ✅ | 商品详情页链接                                                                                    |
| `items[].product_name` | string ≤255 | ✅ | 商品名称                                                                                       |
| `items[].product_description` | string | 否 | 商品描述                                                                                       |
| `items[].unit_price` | string(数字) | ✅ | 单价                                                                                         |
| `items[].quantity` | integer ≥1 | ✅ | 数量                                                                                         |
| `notify_url` | url ≤500 | 条件必填 | 交易结果异步回调地址，域名须与下单所用应用绑定的网站域名一致；传了 `payment_method_key` 时必填且域名须与该渠道绑定站点一致                        |
| `return_url` | url ≤500 | 条件必填 | 支付成功跳转地址，规则同上                                                                              |
| `cancel_url` | url ≤500 | 条件必填 | 取消/失败跳转地址，规则同上                                                                             |
| `sign` | string | ✅ | 见[签名算法](#鉴权与签名三个接口通用)                                                                      |

### 成功响应（HTTP 200）

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "order_no": "ORD2026070612345678",
    "payment_link_token": "PL_AbCdEf123456_20260706123456",
    "payment_url": "{BASE_URL}/payment/PL_AbCdEf123456_20260706123456",
    "pay_url": "https://gateway.example.com/checkout/xxxxx",
    "converted_currency": "USD",
    "converted_amount": "205.20",
    "exchange_rate": "1.080000",
    "payment_method": "paypal",
    "status": "pending"
  }
}
```

| 字段 | 说明 |
|---|---|
| `order_no` | 系统订单号 |
| `payment_link_token` / `payment_url` | 托管收银页地址，把用户导向 `payment_url` 即可完成支付引导 |
| `pay_url` | 支付网关插件渲染的收银台地址（部分渠道会返回，可为空） |
| `converted_currency` / `converted_amount` | 折算为 USD 后的金额（含汇损） |
| `exchange_rate` | 下单时刻锁定的结算汇率（含汇损） |
| `payment_method` | 系统下单时**已锁定的唯一支付方式**，不是候选列表；若指定了 `payment_method_key`，这里返回的就是该渠道 |
| `status` | 创建成功后固定为 `pending` |

### 幂等性

`merchant_order_no` 是幂等键。同一 `merchant_order_no` 重复提交：若订单已存在，直接原样返回该订单信息，不重复创建、不重新计算汇率、不重新走风控。**超时重试时应复用同一个 `merchant_order_no`**（但要重新生成 `Timestamp`/`X-Nonce`/`sign`）。

---

## POST /order/ship（同步物流信息）

```
POST {BASE_URL}/api/order/ship
```

外部系统（物流商/商户自建系统/ERP）在订单发货后，把物流单号同步回订单系统。

### 请求体

```json
{
  "merchant_order_no": "M20260706001",
  "logistics_company": "DHL",
  "tracking_number": "1234567890",
  "tracking_url": "https://www.dhl.com/track?id=1234567890",
  "shipped_at": "2026-09-03T10:00:00+00:00",
  "sign": "…"
}
```

### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `merchant_order_no` | string ≤64 | ✅ | 下单时使用的商户订单号，用于定位订单（按当前应用所属商户查找，查不到跨商户的订单） |
| `logistics_company` | string ≤100 | ✅ | 承运商代码，必须是后台「物流承运商」列表（`CarrierResource`）里已启用的 `carrier_code`（如 `DHL`、`FEDEX`），大小写不敏感；不在列表里会被拒绝，需要联系管理员添加 |
| `tracking_number` | string ≤100 | ✅ | 物流单号 |
| `tracking_url` | url ≤255 | 否 | 物流追踪链接，可不传 |
| `shipped_at` | date | ✅ | 发货时间，建议 ISO 8601 格式（如 `2026-09-03T10:00:00+00:00`） |
| `sign` | string | ✅ | 见[签名算法](#鉴权与签名三个接口通用) |

### 成功响应（HTTP 200）

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "order_no": "ORD2026070612345678",
    "merchant_order_no": "M20260706001",
    "status": "shipped",
    "logistics_company": "DHL",
    "tracking_number": "1234567890",
    "tracking_url": "https://www.dhl.com/track?id=1234567890",
    "shipped_at": "2026-09-03T10:00:00+00:00"
  }
}
```

`status` 返回订单**同步后**的最新状态：如果订单原本是 `paid`（待发货），提交物流信息后会自动推进为 `shipped`；如果订单已经是 `shipped`（或 `partially_refunded`/`disputing`），只更新物流记录，`status` 保持不变。

### 失败响应

```json
{ "code": 422, "msg": "订单当前状态为「completed」，只有已支付、已发货（补发改单）或部分退款状态的订单才能录入物流信息。", "error_code": "INVALID_ORDER_STATUS" }
```

| HTTP | `error_code` | 触发条件 |
|---|---|---|
| 401 | — | 同鉴权失败 |
| 422 | （无 `error_code`，标准校验错误，`errors` 字段含具体字段错误） | 字段格式问题（必填缺失、超长、`tracking_url` 不是合法 URL 等），也包括 `logistics_company` 不在系统承运商列表中（提示联系管理员添加该承运商） |
| 404 | `ORDER_NOT_FOUND` | 该商户名下找不到 `merchant_order_no` 对应的订单 |
| 422 | `INVALID_ORDER_STATUS` | 订单当前状态不允许录入物流：只有 `paid`（已支付待发货）、`shipped`（已发货，视为补发/改单）、`partially_refunded`（部分退款）、`disputing`（争议中）这 4 种状态可以调用；订单处于 `pending`/`failed`/`cancelled`/`expired`/`refunded`/`chargeback`/`completed` 时会被拒绝 |

### 行为说明（对接前务必确认）

1. **覆盖语义**：同一个订单重复提交 `/order/ship`（补发、改单、录错重传）会**直接覆盖**原有物流记录，不会报错也不会保留历史版本——和 `/order/create` "重复提交原样返回、不覆盖"的幂等语义**不一样**，调用前请确认参数无误。
2. **不会触发 webhook**：这个接口只更新订单状态和物流字段，**不会**往 `notify_url` 推送通知（当前系统的交易结果 webhook 只在订单变为 `paid` 时触发，见下一节）。如果需要在发货后通知你自己的系统，需要你自己在调用 `/order/ship` 成功后自行处理，或改用 `/order/query` 轮询。
3. **操作人标记**：这类物流记录在后台展示为"操作人：API"，用于和人工在后台手动录入的物流区分开。

---

## POST /order/query（查询订单）

```
POST {BASE_URL}/api/order/query
```

用 `order_no` 或 `merchant_order_no` 二选一查询订单当前状态，用于 `return_url` 落地页展示、或定时对账兜底（防止 webhook 丢失）。鉴权与签名规则和 `/order/create`、`/order/ship` 完全一样，见[第 2 节](#鉴权与签名三个接口通用)。

### 请求体

```json
{
  "order_no": "ORD2026070612345678",
  "sign": "…"
}
```

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `order_no` | string | 二选一 | 系统订单号 |
| `merchant_order_no` | string | 二选一 | 商户订单号。两者都传时优先按 `order_no` |
| `sign` | string | ✅ | 见[签名算法](#鉴权与签名三个接口通用) |

### 成功响应（HTTP 200）

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "order_no": "ORD2026070612345678",
    "merchant_order_no": "M20260706001",
    "status": "paid",
    "platform": "wordpress",
    "currency": "EUR",
    "amount": "190.00",
    "converted_amount": "205.20",
    "payment_method": "paypal",
    "created_at": "2026-07-06T12:34:56+00:00"
  }
}
```

### 失败响应

| HTTP | `error_code` | 触发条件 |
|---|---|---|
| 401 | — | 同鉴权失败 |
| 422 | （标准校验错误） | `order_no` 与 `merchant_order_no` 都没传 |
| 404 | `ORDER_NOT_FOUND` | 找不到对应订单 |

---

## Webhook：交易结果通知

### 触发条件（重要，容易被误解）

系统只有在**订单状态变为 `paid`（已支付）** 时才会调用 `notify_url`（从"争议中 `disputing` 恢复为 `paid`"的情形除外，那属于争议胜诉，不算首次支付成功，不会重复通知）。

**其他状态变化都不会触发这个 webhook**，包括：`failed`（支付失败）、`cancelled`（取消）、`expired`（过期）、`refunded`（退款）、`disputing`（争议中）、`chargeback`（拒付），也包括调用 `/order/ship` 后订单从 `paid` 推进到 `shipped`。这些状态目前只在系统后台产生记录/人工提醒，**不会**同步推送给对接方。如果需要感知这些状态，目前只能通过 [POST /order/query](#post-orderquery查询订单) 轮询兜底。

### Payload

订单状态变为 `paid` 时，系统会往创建订单时传入的 `notify_url` 发一个 `POST` 请求。**请求结构和你调用我们接口时完全对称**：签名相关的三个值放在 Header，业务字段 + `sign` 放在 body，验签算法和[第 2 节](#鉴权与签名三个接口通用)的签名算法是同一个函数：

**Header：**

| Header | 说明 |
|---|---|
| `App-ID` | 你的应用 `app_id`，和你平时调用我们接口时用的是同一个值 |
| `Timestamp` | 本次投递（含每次重试）发出时的 Unix 时间戳（秒级） |
| `X-Nonce` | 本次投递（含每次重试）随机生成的字符串 |

**Body：**

```json
{
  "order_no": "ORD2026070612345678",
  "merchant_order_no": "M20260706001",
  "status": "paid",
  "currency": "EUR",
  "amount": "190.00",
  "converted_currency": "USD",
  "converted_amount": "205.20",
  "payment_method": "paypal",
  "occurred_at": "2026-07-06T12:40:00+00:00",
  "sign": "…"
}
```

| 字段 | 说明 |
|---|---|
| `order_no` / `merchant_order_no` | 系统订单号 / 商户订单号 |
| `status` | 目前恒为 `paid`（见上方触发条件说明） |
| `currency` / `amount` | 订单原始币种与金额（字符串） |
| `converted_currency` / `converted_amount` | 折算为 USD 后的币种与金额（字符串） |
| `payment_method` | 实际收款渠道 |
| `occurred_at` | 本次通知触发的时刻（ISO 8601），不是订单支付的原始时刻 |
| `sign` | 见下方验签算法 |

### 验签（对接方必须实现）

**和第 2 节完全同一个算法**，只是这次是校验我们发给你的请求：

```
StringToSign = App-ID(Header) + "\n" + Timestamp(Header) + "\n" + X-Nonce(Header) + "\n" + 规范化 body（不含 sign）
Signature    = HMAC-SHA256(StringToSign, api_key)
```

规范化规则不变：关联数组按 key 递归 `ksort`，数字索引列表保持原顺序，`json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`。如果你已经实现了第 2 节调用我们接口时用的"计算 sign"函数，这里**直接复用同一个函数**即可，不需要另外写一套。

```php
$appId = $_SERVER['HTTP_APP_ID'] ?? '';
$timestamp = $_SERVER['HTTP_TIMESTAMP'] ?? '';
$nonce = $_SERVER['HTTP_X_NONCE'] ?? '';

$body = json_decode(file_get_contents('php://input'), true);
$sign = $body['sign'] ?? '';

// canonicalize()/ksortRecursive() 就是第 2 节参考实现里的那两个函数，原样复用
$expected = hash_hmac('sha256',
    $appId . "\n" . $timestamp . "\n" . $nonce . "\n" . canonicalize($body),
    $apiKey
);

if (! $appId || ! $timestamp || ! $nonce || ! hash_equals($expected, $sign)) {
    http_response_code(401);
    exit;
}
```

> 每次投递（含每次自动重试）的 `Timestamp`/`X-Nonce`/`sign` 都是**发送那一刻现算的**，不是订单状态变化时就固定好的一份——所以同一笔通知的多次重试，三个值都会不一样，这是正常现象，不代表签名错误。因此**不建议**对 webhook 的 `Timestamp` 做类似"入站请求"那样的 ±5 分钟过期校验（重试可能间隔长达 1 小时），`Timestamp`/`X-Nonce` 在这里主要作用是让签名不可预测，是否做防重放校验由你自行决定。
>
> 如果你的 Application 没有配置 `api_key`（正常情况下不会发生，创建应用时系统会自动生成），本次通知就不会带 `App-ID`/`Timestamp`/`X-Nonce` 三个 Header 和 `sign` 字段——这种情况说明应用配置有问题，应联系系统管理员核实，而不是当作正常场景处理。

### 响应要求与重试机制

- 对接方收到 webhook 后需要在 **10 秒内**返回 HTTP 2xx，视为通知成功；其他状态码、超时、连接失败都视为失败。
- 失败会自动重试，最多 **5 次**，间隔 **30 秒 → 5 分钟 → 30 分钟 → 1 小时**，5 次仍失败后放弃，不再重试。
- 因此对接方的 webhook 处理**必须幂等**（同一 `order_no` 可能因为重试收到多次相同 payload，按 `order_no` + `status` 判断，已处理过直接返回 200，不要重复入账/重复发货）。
- 处理逻辑应尽量快返回（先记录/入队，再异步处理耗时逻辑），避免因为处理慢导致系统侧判定超时而触发不必要的重试。

---

## 订单状态枚举

| 状态值 | 含义 | 是否触发 webhook | 是否允许调用 `/order/ship` |
|---|---|---|---|
| `pending` | 待支付 | 否 | 否 |
| `paid` | 已支付（待发货） | ✅ 是 | ✅ 是 |
| `shipped` | 已发货 | 否 | ✅ 是（视为补发/改单） |
| `completed` | 已完成 | 否 | 否（终态） |
| `partially_refunded` | 部分退款 | 否 | ✅ 是 |
| `refunded` | 已全额退款 | 否 | 否（终态） |
| `disputing` | 争议中 | 否 | ✅ 是 |
| `chargeback` | 已拒付 | 否 | 否（终态） |
| `failed` | 支付失败 | 否 | 否（终态） |
| `cancelled` | 已取消 | 否 | 否（终态） |
| `expired` | 支付链接已过期 | 否 | 否（终态） |

终态订单（`completed`/`refunded`/`chargeback`/`failed`/`cancelled`/`expired`）不会再接受任何状态覆盖。

---

## 错误码汇总

| HTTP | `error_code` | 出现的接口 | 说明 |
|---|---|---|---|
| 401 | — | 全部 | 鉴权失败（参数缺失/时间戳过期/Nonce 重复/App-ID 无效/签名错误） |
| 422 | — （标准校验错误，`errors` 字段带具体字段信息） | 全部 | 请求体字段格式不对 |
| 422 | `AMOUNT_MISMATCH` | `/order/create` | `amount` 与公式计算结果差超过 0.01 |
| 422 | `ITEMS_SUBTOTAL_MISMATCH` | `/order/create` | `subtotal` 与明细之和对不上 |
| 422 | `CALLBACK_DOMAIN_NOT_ALLOWED` | `/order/create` | 回调/跳转地址域名不在商户白名单内 |
| 422 | `PAYMENT_METHOD_NOT_AVAILABLE` | `/order/create` | 指定的 `payment_method_key` 不存在或已停用 |
| 422 | `PAYMENT_METHOD_DOMAIN_MISMATCH` | `/order/create` | 指定渠道时回跳地址缺失或域名不匹配 |
| 409 | `NO_AVAILABLE_PAYMENT_METHOD` | `/order/create` | 支付组内所有渠道都被风控拦截，或支付组不存在/未启用 |
| 404 | `ORDER_NOT_FOUND` | `/order/query`、`/order/ship` | 找不到对应订单 |
| 422 | `INVALID_ORDER_STATUS` | `/order/ship` | 订单当前状态不允许录入物流 |

---

## 对接前需要准备的信息

| 事项 | 说明 |
|---|---|
| `API Base URL` | 订单系统部署地址，替换文档里的 `{BASE_URL}` |
| `App-ID` / `API Key` | 系统后台"应用管理"创建应用后自动生成，`App-ID` 明文可见，`API Key` 只在创建时展示一次，请通过安全渠道（不要用邮件明文）交给对接方 |
| `group_key` | 系统后台"支付组"配置的支付组标识，需要提前建好并告知对接方 |
| `payment_method_key`（可选） | 仅当对接方需要指定固定渠道收款时才用，取值为后台"支付方式"的 `method_code` |
| 回跳域名一致性 | 对接方下单时传的 `notify_url`/`return_url`/`cancel_url` 域名必须与本次调用所用应用（`App-ID`）在后台绑定的网站域名一致，否则下单直接被拒（`CALLBACK_DOMAIN_NOT_ALLOWED`）。域名比对会忽略大小写、`www.` 前缀与端口号，并兼容裸域名与带路径写法；应用未绑定网站域名时，只要传了任一非空回跳地址就会被拒 |
| 支持的 `platform` / `currency` 取值 | 由系统配置动态维护，请在后台确认当前实际配置的枚举值后告知对接方，不要凭文档示例假设 |

---

## 常见对接问题

1. **金额字段一律传字符串**（如 `"190.00"` 而不是 `190.00`），避免序列化差异导致签名或金额校验出错。
2. **签名规范化算法必须和服务端逐字节一致**：出站请求（调用 create/query/ship）和系统推给你的 webhook 验签是**同一套算法**（递归排序 + 三段 StringToSign），你只需要实现一次就能两边复用，但规范化规则（关联数组递归 ksort、列表保持原序、`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`）本身仍然是最容易出错的地方。
3. **服务器时钟需要与标准时间同步**：调用我们接口时 `Timestamp` 误差超过 5 分钟会被直接拒绝（401），排查签名问题时先确认这一点；但反过来验证我们推给你的 webhook 时，**不建议**对 `Timestamp` 做同样的过期校验（见第 6 节说明，重试会导致 `Timestamp` 是之后现算的）。
4. **`notify_url`/`return_url`/`cancel_url` 的域名必须与下单所用应用绑定的网站域名一致**（忽略大小写、`www.` 前缀与端口号），不一致会被拒单（`CALLBACK_DOMAIN_NOT_ALLOWED`）。
5. **`/order/create` 和 `/order/ship` 的幂等语义不同**：前者"重复提交=原样返回，不覆盖"；后者"重复提交=直接覆盖旧物流记录"。
6. **只有 `paid` 状态会触发 webhook**，`shipped`/`refunded`/`cancelled` 等状态变化都不会主动通知，需要轮询 `/order/query` 兜底。
7. Webhook 接收端处理要快（系统侧超时判定是 10 秒），耗时逻辑放异步队列处理，并保证按 `order_no` 幂等（因为失败会自动重试最多 5 次，且每次重试的签名 Header 都不同）。

---

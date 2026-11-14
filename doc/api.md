# 订单系统 API 对接文档

> 基于当前系统代码（`routes/api.php`、`ApiAuthentication` 中间件、`Api\OrderController`、`OrderNotificationService`）整理，供外部系统对接使用。文档里的 `{BASE_URL}` 请替换为实际部署地址（不含 `/api`）。Postman 环境变量 `base_url` 则需要包含 `/api`，见[Postman 联调](#postman-联调)。商户接口受部署配置 `API_DOMAIN` 限制，使用前请确认实际 API 域名。

在线文档：系统提供 `/docs/api/zh_CN`（中文）与 `/docs/api/en`（English），也可从应用管理中的“API 文档”进入。网页支持固定目录锚点、语言切换和示例代码复制，仅显示通用协议与占位示例，不展示真实商户凭证。网页末尾提供币种、platform 与全部订单状态参考附录，正文可直接跳转；币种和 platform 列表读取当前系统配置。

## 目录

1. [对接流程总览](#对接流程总览)
2. [鉴权与签名（三个接口通用）](#鉴权与签名三个接口通用)
3. [POST /order/create（创建订单）](#post-ordercreate创建订单)
4. [广告转化通知（可选）](#广告转化通知可选)
5. [POST /order/ship（同步物流信息）](#post-ordership同步物流信息)
6. [POST /order/query（查询订单）](#post-orderquery查询订单)
7. [Webhook：交易结果通知](#webhook交易结果通知)
8. [订单状态枚举](#订单状态枚举)
9. [错误码汇总](#错误码汇总)
10. [对接前需要准备的信息](#对接前需要准备的信息)
11. [常见对接问题](#常见对接问题)
12. [Postman 联调](#postman-联调)
13. [外部物流回传协议（附件 v1.1）](#外部物流回传协议附件-v11)
14. [组合付款与 V2 通知](#组合付款与-v2-通知)

---

## 对接流程总览

```
外部系统                                          订单系统
────────                                        ────────
1. POST /order/create  ───────────────────────►  创建订单，锁定支付方式
   ◄──────────────────────────────────────────  返回 payment_url
2. 引导用户跳转 payment_url  ───────────────────►  托管收银页，用户完成支付
3. 支付结果产生
   ◄── POST notify_url（webhook，首次 paid）───  交易结果异步通知
4. 用户浏览器跳回 return_url / cancel_url（仅展示，不可作为状态依据）
5. 兜底：POST /order/query 查询系统已保存的订单状态
6. 发货后：POST /order/ship  ───────────────────►  同步物流单号（不产生交易结果 webhook）
```

**核心原则**：订单状态以 webhook 通知 + 主动查询为准，`return_url` 跳转只是用户体验展示，不可信。

---

## 鉴权与签名（三个接口通用）

`/order/create`、`/order/query`、`/order/ship` 三个接口都走同一套 App-ID + 签名鉴权（`ApiAuthentication` 中间件）。

**这套算法和 [Webhook 交易结果通知](#webhook交易结果通知)的验签算法完全一致**，只是方向相反：你调用我们的接口时，你按下面的规则计算 `sign` 放进请求；我们推 webhook 给你时，我们按同样的规则计算 `sign` 放进请求。也就是说你只需要实现**一个**"计算/校验签名"的函数，两个方向直接复用，不用维护两套逻辑。

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

`notify_url`、`return_url`、`cancel_url` 在两种建单模式下都必填。当前普通路由已关闭与应用 `website` 的域名一致性校验；指定 `payment_method_key` 时，三个 URL 的域名仍必须与渠道绑定站点一致，比较时忽略大小写、`www.` 前缀和端口。

未传 `payment_method_key` 时，系统从该支付组内通过可用性与限额检查的渠道中，按“当天已成交金额 ÷ 组内权重”升序、权重降序、ID 升序排列，并从前三个候选随机选择一个；不足三个时全部参与。若网关建单或商品准备失败，本次请求立即结束，返回 `502 GATEWAY_ERROR`，提示“建单失败，请重试”。重试应复用原商户订单号，重新校验并沿用原渠道补建；普通及指定渠道订单都不会在本次请求或后续重试中切换到其他通道。同渠道 HTTP 连接失败的重试仍按服务端配置执行。

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
  "ad_params": {
    "meta": { "fbc": "fb.1.1700000000000.abc123", "fbp": "fb.1.1700000000000.xyz789" },
    "google": { "gclid": "Cj0KCQiA..." },
    "tiktok": { "ttclid": "ttclid.abc123" }
  },
  "sign": "…"
}
```

### 字段说明

| 字段 | 类型 | 必填 | 说明                                                                                         |
|---|---|---|--------------------------------------------------------------------------------------------|
| `merchant_order_no` | string ≤64 | ✅ | 商户自己的订单号，是**幂等键**（与商户账号组合唯一）。币种和原始请求应付金额一致时重复提交返回旧订单，否则返回冲突错误 |
| `platform` | string ≤50 | ✅ | 电商网站平台类型枚举，合法值列表由系统配置 `order.platforms` 动态维护，不在列表内直接拒单                                     |
| `currency` | string(3) | ✅ | 下单币种（如 `USD`/`EUR`/`JPY`/`GBP`），须在系统支持的币种列表内                                                     |
| `group_key` | string ≤50 | ✅ | 支付方式组标识（系统后台"支付组"配置）。即使传了 `payment_method_key` 也仍然必填                                       |
| `payment_method_key` | string ≤50 | 否 | **指定支付渠道**（取值为后台"支付方式"的 `method_code`）。传了会跳过支付组路由与限额风控，直接用该渠道，失败不自动切换；渠道须为商户自有或已分配且启用，三个回跳地址的域名必须与该渠道绑定站点一致 |
| `subtotal` | string(数字) | ✅ | 商品小计，须等于 `items[].unit_price × quantity` 之和                                                |
| `shipping_fee` | string(数字) | ✅ | 运费                                                                                         |
| `discount` | string(数字) | ✅ | 折扣（正数表示减免）                                                                                 |
| `tax` | string(数字) | ✅ | 税金                                                                                         |
| `amount` | string(数字) | ✅ | 应付总额，必须严格等于 `subtotal + shipping_fee - discount + tax`，误差超过 0.01 直接拒单                      |
| `customer.first_name`/`last_name`/`email` | string | ✅ | 客户信息                                                                                       |
| `customer.phone` | string ≤30 | 否 | 客户手机号，部分电商网站结账页不采集该字段时可不传                                                                    |
| `shipping_address.line1` | string ≤255 | ✅ | 地址行 1                                                                                      |
| `shipping_address.line2` | string ≤255 | 否 | 地址行 2                                                                                      |
| `shipping_address.city` | string ≤100 | ✅ | 城市                                                                                         |
| `shipping_address.state` | string ≤100 | 否 | 州/省                                                                                        |
| `shipping_address.country` | string(2) | ✅ | ISO 3166-1 alpha-2（如 `DE`、`US`）                                                            |
| `shipping_address.zip` | string ≤20 | 否 | 邮编，部分电商网站结账页不采集该字段时可不传                                                                    |
| `items` | array ≥1 | ✅ | 商品明细                                                                                       |
| `items[].product_sku` | string ≤64 | 否 | 商户侧商品编号                                                                                    |
| `items[].product_id` | string ≤64 | ✅ | 商户侧商品唯一标识,wordpress 传入 商品 的product_id                                                      |
| `items[].product_url` | url ≤500 | ✅ | 商品详情页链接                                                                                    |
| `items[].product_name` | string ≤255 | ✅ | 商品名称                                                                                       |
| `items[].product_description` | string | 否 | 商品描述                                                                                       |
| `items[].unit_price` | string(数字) | ✅ | 单价                                                                                         |
| `items[].quantity` | integer ≥1 | ✅ | 数量                                                                                         |
| `notify_url` | url ≤500 | ✅ | 交易结果异步回调地址，**必填**。普通路由当前不校验应用网站域名一致性；指定 `payment_method_key` 时须与渠道绑定站点域名一致 |
| `return_url` | url ≤500 | ✅ | 支付成功跳转地址，**必填**，域名规则同上                                                                              |
| `cancel_url` | url ≤500 | ✅ | 取消/失败跳转地址，**必填**，域名规则同上                                                                             |
| `send_mail` | string，`Y`/`N` | 否 | 是否给客户发送付款链接邮件，`Y` 请求发送、`N` 或不传不发送。需在应用中开启邮件并配置完整的发件人、发信驱动及凭证，发送统一使用该应用配置；应用缺配置时记录发送失败，不使用支付方式或平台账号。邮件正文里的付款链接固定用本次下单返回的 `pay_url` |
| `ad_params` | object | 否 | 广告追踪参数，按广告平台分 key 透传（目前支持 `meta`/`google`/`tiktok`），用于[广告转化通知](#广告转化通知可选)，系统原样落库、不校验里面的具体字段 |
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
    "status": "pending",
    "order_status": "pending",
    "settlement_status": null,
    "shipping_status": "unshipped"
  }
}
```

| 字段 | 说明 |
|---|---|
| `order_no` | 系统订单号 |
| `payment_link_token` / `payment_url` | 托管收银页地址，把用户导向 `payment_url` 即可完成支付引导 |
| `pay_url` | 支付网关插件返回的收银台地址。新单正常建单成功后返回；幂等请求若命中已离开待支付流程且缺少链接的订单，可能为空，应结合 `status` 处理 |
| `converted_currency` / `converted_amount` | 折算为 USD 后的金额（含汇损） |
| `exchange_rate` | 下单时刻锁定的结算汇率（含汇损） |
| `payment_method` | **订单锁定的唯一支付方式**，后续重试沿用原渠道，不重新选择；若原订单指定了 `payment_method_key`，这里返回该指定渠道 |
| `status` | 兼容状态：本地 `pending_settlement` 返回 `paid`，其余状态原样返回；新单通常为 `pending`，幂等请求可能返回已收款或售后状态 |
| `order_status` | 本地真实订单主状态，包含新增的 `pending_settlement`；见下方状态枚举 |
| `settlement_status` | 本单真实结算记录状态：`waiting_shipment` / `pending` / `settled` / `cancelled`；尚无记录或历史订单为 `null` |
| `shipping_status` | 是否存在有效物流记录：`shipped` / `unshipped`；与结算状态独立 |

应用可在后台开启自动折扣（默认关闭）：按请求 `amount` 的原币种金额，不超过 200 随机减 0.01～0.10，超过 200 随机减 0.01～0.50；极小金额至少保留 0.01，不足减免时跳过。请求仍提交原始金额与原始折扣，服务端首次建单时增加折扣并减少实际应付金额。响应中的折算金额、订单查询及支付通知中的金额反映折扣后的实际付款金额。重试仍提交原始请求金额，服务端复用首次折扣，不重新随机。

### 幂等性

`merchant_order_no` 是商户内的幂等键。同一 `merchant_order_no` 重复提交时先核对币种和原始请求应付金额；不一致返回 `409 ORDER_DETAILS_CONFLICT`，不修改原订单或请求远端。已有支付链接、已收款或已离开 `pending/failed` 时返回订单当前结果。只有无支付链接、未收款的 `pending/failed` 订单会补建：

- 普通订单重新检查原渠道启用、未删除、商户授权、原支付组有效、组内成员关系及当前风控；不合格返回 `409 NO_AVAILABLE_PAYMENT_METHOD`。即使组内有其他可用渠道，也不另选通道。
- 原订单指定了渠道时，按保存的原渠道 ID、商户授权、启用/删除状态及三个回跳 URL 重新校验；失效返回 `422 PAYMENT_METHOD_NOT_AVAILABLE`，域名不符返回 `422 PAYMENT_METHOD_DOMAIN_MISMATCH`。新请求的 `group_key` / `payment_method_key` 或 URL 不能改变原订单的支付组、指定渠道模式及回跳地址。

补建复用同一本地订单及原渠道，`order_no`、付款 token、远端引用、全部财务快照、原始商品、应付金额、汇率和首次自动折扣不变，复用此前已创建的商品。历史渠道 ID 为空时仅补齐同一渠道关联，不改引用或财务快照。建单失败即返回，不继续尝试其他通道。**超时或建单失败后重试应复用同一个 `merchant_order_no`**，并重新生成 `Timestamp`/`X-Nonce`/`sign`。

历史订单若已保存独立远端引用，重试继续沿用；系统保留历史旧尝试的回调校验，避免迟到结果覆盖当前状态。同渠道补建依赖支付插件对相同远端引用的幂等处理。

---

## 广告转化通知（可选）

下单时如果传了 `ad_params`，系统会在订单**首次变为已支付**时，由服务端直接调用对应广告平台的转化 API（Meta Conversions API / Google Ads Click Conversion / TikTok Events API），告知广告方"这笔订单已支付成功"。

**触发条件只有两个，同时满足即直接同步，和支付方式是否配置"允许返回源站"（`allow_returned_source`）没有关系**：

1. 下单时 `ad_params` 传了某个广告平台的追踪参数；
2. 该笔订单所属的应用（`App-ID`）在系统后台"应用管理"里配置了该平台的转化 API 凭证。

两者缺一都不会触发——某个平台没配凭证时，即使传了该平台的 `ad_params` 也不会上报（也不会报错，直接静默跳过）。

### `ad_params` 结构

按广告平台分 key，value 是该平台需要的追踪参数：

```json
{
  "ad_params": {
    "meta": { "fbc": "fb.1.1700000000000.abc123", "fbp": "fb.1.1700000000000.xyz789" },
    "google": { "gclid": "Cj0KCQiA..." },
    "tiktok": { "ttclid": "ttclid.abc123" }
  }
}
```

系统只会按需读取以下字段，其余字段会原样存下来但不会使用：

| 平台 | 字段 | 说明 |
|---|---|---|
| `meta` | `fbc` / `fbp` | Facebook Click ID / Browser ID，取自客户浏览器的 `_fbc`/`_fbp` cookie |
| `google` | `gclid` | Google Ads 点击 ID，**缺失时无法归因**，系统会跳过该笔订单的 Google 转化上报 |
| `tiktok` | `ttclid` | TikTok 点击 ID |

即使追踪 ID 缺失，Meta/TikTok 仍会用订单的 `customer.email`/`customer.phone`（哈希后）作为兜底归因信号一并上报；Google 强依赖 `gclid`，缺失时直接不生成本次上报。

### 凭证配置（与下单接口无关，由商户在后台单独维护）

三个平台各自的转化 API 凭证需要商户在后台"应用管理"对应应用的编辑页里配置：

| 平台 | 需要的凭证 |
|---|---|
| Meta | Pixel ID、Access Token（可选：Test Event Code、事件名称，事件名称留空默认 `Purchase`） |
| Google Ads | Customer ID、Conversion Action ID、Developer Token、OAuth Client ID/Secret/Refresh Token（可选：Login Customer ID，仅 MCC 经理账号场景需要）。Google 没有单独的"事件名称"配置——`Conversion Action ID` 本身就是在 Google Ads 后台建号时定义好的具体转化事件，已经等价于事件类型 |
| TikTok | Pixel Code、Access Token（可选：事件名称，留空默认 `CompletePayment`） |

Meta/TikTok 的"事件名称"只有在商户前端页面**同时**用像素上报了同一笔订单的转化事件、且需要和这次服务端上报按事件名去重匹配时才需要修改；多数场景保持默认值（`Purchase`/`CompletePayment`）即可。

### 重试与可靠性

- 上报失败会自动重试，最多 **5 次**，间隔 **30 秒 → 5 分钟 → 30 分钟 → 1 小时**，5 次仍失败后放弃，不再重试。
- 广告转化通知与订单本身的支付状态完全独立：上报失败**不会**影响订单状态、不会影响 [Webhook 交易结果通知](#webhook交易结果通知)的投递，两者互不阻塞。
- 目前没有对接方可查询的接口——如果需要核对某笔订单的广告转化上报结果，请联系系统管理员在后台"订单详情 → 广告转化通知记录"里查看。

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
    "order_status": "shipped",
    "settlement_status": "pending",
    "shipping_status": "shipped",
    "logistics_company": "DHL",
    "tracking_number": "1234567890",
    "tracking_url": "https://www.dhl.com/track?id=1234567890",
    "shipped_at": "2026-09-03T10:00:00+00:00"
  }
}
```

`order_status` 返回保存物流后的真实主状态，`status` 仍采用兼容映射（`pending_settlement` → `paid`）。发货后起算模式首次录入有效物流后进入 `shipped` 并启动结算周期；0 天周期可能直接完成结算而返回 `completed`。支付后起算模式保持 `pending_settlement` 或已结算的 `completed`，录入物流不会倒退主状态。部分退款、争议等售后状态优先保留。`shipping_status` 返回 `shipped`，仅代表本地已有有效物流记录。

### 失败响应

```json
{ "code": 422, "msg": "订单当前状态为「refunded」，只有已收款且未全额退款、未拒付的订单才能录入物流信息。", "error_code": "INVALID_ORDER_STATUS" }
```

| HTTP | `error_code` | 触发条件 |
|---|---|---|
| 401 | — | 同鉴权失败 |
| 422 | （无 `error_code`，标准校验错误，`errors` 字段含具体字段错误） | 字段格式问题（必填缺失、超长、`tracking_url` 不是合法 URL 等），也包括 `logistics_company` 不在系统承运商列表中（提示联系管理员添加该承运商） |
| 404 | `ORDER_NOT_FOUND` | 该商户名下找不到 `merchant_order_no` 对应的订单 |
| 422 | `INVALID_ORDER_STATUS` | 订单当前状态不允许录入物流：允许 `paid`、`pending_settlement`、`shipped`、`completed`、`partially_refunded`、`disputing` 录入或覆盖物流；`pending`、`failed`、`cancelled`、`expired`、`refunded`、`chargeback`、`dispute_review` 不允许调用。已完成仅表示结算完成，仍可补录物流 |

### 行为说明（对接前务必确认）

1. **覆盖语义**：同一个订单重复提交 `/order/ship`（补发、改单、录错重传）会**直接覆盖**原有物流记录，物流表不保留多批历史；已生成的商户网站回传快照及请求结果独立保留。此行为和 `/order/create` "重复提交原样返回、不覆盖"的幂等语义**不一样**，调用前请确认参数无误。
2. **不会触发交易结果 webhook**：此接口不会往 `notify_url` 推送发货通知；调用方可根据成功响应中的物流字段和订单状态处理后续流程。`/order/query` 返回订单状态和 `logistics`（组合父单在 `children[].logistics`）。录入后系统另会将物流同步任务加入队列，发给订单关联的 WordPress 支付插件，具体协议见[物流同步接口说明](wordpress/s-system-sync-tracking.md)。应用开启物流回传时，还会独立异步推送到该应用配置的商户网站接口，见[外部物流回传协议](#外部物流回传协议附件-v11)；本接口成功只代表本地保存成功，不能据此判断任何远端已同步成功。
3. **操作人标记**：这类物流记录在后台展示为"操作人：API"，用于和人工在后台手动录入的物流区分开。

---

## POST /order/query（查询订单）

```
POST {BASE_URL}/api/order/query
```

用 `order_no` 或 `merchant_order_no` 查询当前商户的订单，用于 `return_url` 落地页展示或定时对账兜底。**此接口读取系统本地已保存的状态，不会向支付网关发起实时查询，也不修改订单状态。** 编号放在 POST JSON 请求体中并参与签名。鉴权与签名规则和 `/order/create`、`/order/ship` 完全一样，见[鉴权与签名](#鉴权与签名三个接口通用)。

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
| `merchant_order_no` | string | 二选一 | 商户订单号。至少传一个编号；两者都传时按 AND 同时匹配，必须指向同一订单 |
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
    "order_status": "pending_settlement",
    "settlement_status": "pending",
    "shipping_status": "unshipped",
    "platform": "wordpress",
    "currency": "EUR",
    "amount": "190.00",
    "converted_amount": "205.20",
    "payment_method": "paypal",
    "created_at": "2026-07-06T12:34:56+00:00"
  }
}
```

创建、查询及上传物流三个成功响应均包含 `status`、`order_status`、`settlement_status`、`shipping_status`，含义与创建订单响应一致。`status` 保留旧客户端可识别的值，新增待结算仅通过 `order_status` 精确表达。查询不返回 `pay_url` 或付款 token；物流明细位于 `logistics` 或组合父单的 `children[].logistics`。需要保存的付款链接以建单响应为准；物流字段以 `/order/ship` 成功响应为准。

### 失败响应

| HTTP | `error_code` | 触发条件 |
|---|---|---|
| 401 | — | 同鉴权失败 |
| 422 | （标准校验错误，返回 `message`、`errors`） | `order_no` 与 `merchant_order_no` 都没传，或编号类型错误 |
| 404 | `ORDER_NOT_FOUND` | 找不到对应订单 |

---

## Webhook：交易结果通知

### 触发条件（重要，容易被误解）

普通单只在**首次确认支付成功**时创建交易结果通知，投递到建单时保存的 `notify_url`。该支付事实与本地履约、结算阶段独立：订单可能已进入 `pending_settlement`，或因 0 天周期已进入 `completed`，通知中的 `status` 仍固定为 `paid`。已收款订单的状态恢复、结算或售后变化不会重复创建首次支付通知；已创建通知的失败重试仍可能继续投递。

**其他状态变化都不会触发这个 webhook**，包括：`failed`（支付失败）、`cancelled`（取消）、`expired`（过期）、`refunded`（退款）、`disputing`（争议中）、`chargeback`（拒付），也包括发货推进为 `shipped`、结算推进为 `completed`。这些状态目前只在系统后台产生记录/人工提醒，**不会**同步推送给对接方。如果需要感知这些状态，目前只能通过 [POST /order/query](#post-orderquery查询订单) 轮询兜底。

组合单在每笔子单首次确认付款时都通知；顶层原订单仅在全部付清时为 `paid`，协议见[组合付款与 V2 通知](#组合付款与-v2-通知)。

### Payload（V1 普通单）

符合上述首次支付条件时，系统会往创建订单时传入的 `notify_url` 发一个 `POST` 请求。**请求结构和你调用我们接口时完全对称**：签名相关的三个值放在 Header，业务字段 + `sign` 放在 body，验签算法和[第 2 节](#鉴权与签名三个接口通用)的签名算法是同一个函数：

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
| `currency` / `amount` | 订单币种与实际应付金额（字符串，应用自动折扣已扣除） |
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

> 每次投递（含每次自动重试）的 `Timestamp`/`X-Nonce`/`sign` 都在**实际发送时重新生成**；业务 payload（含 `occurred_at`）沿用首次通知快照。接收方可按每次投递的 `Timestamp` 校验时间窗口，并保存 nonce 防重放；即使重试间隔一小时，也会使用新的发送时间，不能因此跳过时间校验。上方代码展示 HMAC 校验，完整接收端还应核对 App-ID、时间戳和 nonce，并按业务订单幂等处理。
>
> 如果你的 Application 没有配置 `api_key`（正常情况下不会发生，创建应用时系统会自动生成），本次通知就不会带 `App-ID`/`Timestamp`/`X-Nonce` 三个 Header 和 `sign` 字段——这种情况说明应用配置有问题，应联系系统管理员核实，而不是当作正常场景处理。

### 响应要求与重试机制

- 对接方收到 webhook 后需要在 **10 秒内**返回 HTTP 2xx，视为通知成功；其他状态码、超时、连接失败都视为失败。
- 默认总共最多投递 **5 次（首次 1 次 + 重试 4 次）**，失败后依次等待 **30 秒 → 5 分钟 → 30 分钟 → 1 小时**；次数和间隔可由系统配置调整，实际执行受队列与调度影响。
- 因此对接方的 webhook 处理**必须幂等**（同一 `order_no` 可能因为重试收到多次相同 payload，按 `order_no` + `status` 判断，已处理过直接返回 200，不要重复入账/重复发货）。
- 处理逻辑应尽量快返回（先记录/入队，再异步处理耗时逻辑），避免因为处理慢导致系统侧判定超时而触发不必要的重试。

---

## 订单状态枚举

| 状态值 | 含义 | 是否触发 webhook | 是否允许调用 `/order/ship` |
|---|---|---|---|
| `pending` | 待支付 | 否 | 否 |
| `paid` | 已支付，发货后起算模式待发货；兼容 `status` 也用于表示待结算 | ✅ 首次确认支付时 | ✅ 是 |
| `pending_settlement` | 已支付，支付后起算模式待结算；仅在 `order_status` 返回 | 首次支付通知仍为 `paid` | ✅ 是 |
| `shipped` | 已发货 | 否 | ✅ 是（视为补发/改单） |
| `completed` | 本单结算已完成，仍可发生争议、退款、拒付 | 否 | ✅ 是（可补录物流） |
| `partially_refunded` | 部分退款 | 否 | ✅ 是 |
| `refunded` | 已全额退款 | 否 | 否（终态） |
| `disputing` | 争议中 | 否 | ✅ 是 |
| `dispute_review` | 人工争议审核中 | 否 | 否 |
| `chargeback` | 已拒付 | 否 | 否（终态） |
| `failed` | 支付失败 | 否 | 否（终态） |
| `cancelled` | 已取消 | 否 | 否（终态） |
| `expired` | 支付链接已过期 | 否 | 否（终态） |

支付后起算模式按“支付成功 → 待结算 → 实际结算成功 → 已完成”推进；发货后起算模式按“支付成功 → 待发货 → 首次上传有效物流并起算 → 已发货 → 实际结算成功 → 已完成”推进。0 天周期可直接完成结算；“已完成”不表示保证金已经全部释放，也不阻止后续售后。部分退款状态不被剩余款项结算覆盖。

网关回调保留重复、乱序及未收款回退保护。内部主动查询得到 `paid` 只确认支付事实，再依据实际结算、物流、退款/拒付及人工审核记录恢复主状态，不抹掉既有资金结果，不会将已结算订单降回待发货。商户 `/order/query` 始终只读取本地记录，不执行网关查询、状态修复或资金操作。

---

## 错误码汇总

| HTTP | `error_code` | 出现的接口 | 说明 |
|---|---|---|---|
| 401 | — | 全部 | 鉴权失败（参数缺失/时间戳过期/Nonce 重复/App-ID 无效/签名错误） |
| 422 | — （字段错误） | 全部 | 建单/物流返回 `{code:422,msg:"Validation failed",errors}`；查询返回 `{message,errors}` |
| 422 | `AMOUNT_MISMATCH` | `/order/create` | `amount` 与公式计算结果差超过 0.01 |
| 422 | `ITEMS_SUBTOTAL_MISMATCH` | `/order/create` | `subtotal` 与明细之和对不上 |
| 422 | `MINIMUM_AMOUNT_NOT_MET` | `/order/create` | 折算 USD 金额不足以承担渠道手续费 |
| 422 | `CALLBACK_DOMAIN_NOT_ALLOWED` | `/order/create` | 保留的错误码；普通路由与应用网站的域名一致性检查当前已关闭 |
| 422 | `PAYMENT_METHOD_NOT_AVAILABLE` | `/order/create` | 指定渠道首次建单或重提校验失败：渠道不存在、已删除、不在商户可用范围或已停用 |
| 422 | `PAYMENT_METHOD_DOMAIN_MISMATCH` | `/order/create` | 指定渠道时回跳域名不匹配；URL 缺失由字段校验拦截 |
| 409 | `NO_AVAILABLE_PAYMENT_METHOD` | `/order/create` | 首次建单没有合格候选，或普通订单重试时原渠道不再符合可用性/组内成员/风控要求；重试不改用其他渠道 |
| 409 | `ORDER_DETAILS_CONFLICT` | `/order/create` | 相同商户订单号的币种或应付金额与旧订单不一致 |
| 502 | `GATEWAY_ERROR` | `/order/create` | 网关建单或商品准备失败，提示“建单失败，请重试”；立即结束本次请求。本地订单保留，复用原商户订单号并校验原渠道后可补建，不切换其他通道 |
| 404 | — | `/order/create` | 支付组不存在、不属于商户或未启用，返回 `{code:404,msg:"Resource not found."}` |
| 404 | `ORDER_NOT_FOUND` | `/order/query`、`/order/ship` | 当前商户下找不到匹配订单；查询同时传两个编号时须同时匹配 |
| 422 | `INVALID_ORDER_STATUS` | `/order/ship` | 订单当前状态不允许录入物流 |

---

## 对接前需要准备的信息

| 事项 | 说明 |
|---|---|
| `API Base URL` | 文档 `{BASE_URL}` 为部署地址（不含 `/api`）；Postman `base_url` 为该地址加 `/api`。配置了 `API_DOMAIN` 时必须使用对应域名 |
| `App-ID` / `API Key` | 系统后台"应用管理"创建应用后自动生成，`App-ID` 明文可见，`API Key` 只在创建时展示一次，请通过安全渠道（不要用邮件明文）交给对接方 |
| `group_key` | 系统后台"支付组"配置的支付组标识，需要提前建好并告知对接方 |
| `payment_method_key`（可选） | 仅当对接方需要指定固定渠道收款时才用，取值为后台"支付方式"的 `method_code` |
| 回跳域名一致性 | `notify_url`/`return_url`/`cancel_url` **三个都必填**。普通路由当前不校验与应用网站的域名一致性；指定 `payment_method_key` 时，三个 URL 须与渠道绑定站点域名一致，否则返回 `PAYMENT_METHOD_DOMAIN_MISMATCH`。比较忽略大小写、`www.` 前缀与端口 |
| 支持的 `platform` / `currency` 取值 | 由系统配置动态维护，请在后台确认当前实际配置的枚举值后告知对接方，不要凭文档示例假设 |

---

## 常见对接问题

1. **金额字段一律传字符串**（如 `"190.00"` 而不是 `190.00`），避免序列化差异导致签名或金额校验出错。
2. **签名规范化算法必须和服务端逐字节一致**：出站请求（调用 create/query/ship）和系统推给你的 webhook 验签是**同一套算法**（递归排序 + 三段 StringToSign），你只需要实现一次就能两边复用，但规范化规则（关联数组递归 ksort、列表保持原序、`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`）本身仍然是最容易出错的地方。
3. **服务器时钟需要与标准时间同步**：调用我们接口时 `Timestamp` 误差超过 5 分钟会被直接拒绝（401）。Webhook 每次投递都会刷新时间戳、nonce 和签名，接收方可据此校验每次投递的时间窗口并防重放。
4. **`notify_url`/`return_url`/`cancel_url` 三个都必填**：普通路由的应用网站域名检查当前已关闭；指定渠道时须与渠道绑定站点域名一致（`PAYMENT_METHOD_DOMAIN_MISMATCH`）。
5. **`/order/create` 和 `/order/ship` 的幂等语义不同**：前者币种和原始金额一致时复用本地订单，已有支付链接直接返回，无链接、未收款且为 `pending/failed` 时重新校验并沿用原渠道补建，失败即返回，不切换其他通道。后者"重复提交=直接覆盖旧物流记录"。
6. **普通订单只有首次支付成功会创建交易结果 webhook**，`shipped`/`refunded`/`cancelled` 等状态变化以及已收款订单恢复为 `paid` 都不会创建新的首次支付通知；可轮询 `/order/query` 读取已保存状态，查询本身不会向网关补查。
7. Webhook 接收端在 10 秒内返回 HTTP 2xx，耗时逻辑放异步队列，并按 `order_no` + `status` 幂等。默认最多投递 5 次（含首次），每次重试的签名 Header 都会刷新。

---

## Postman 联调

导入 [API 集合](../postman/Order-System-API.postman_collection.json)和[环境文件](../postman/Order-System-Local.postman_environment.json)，选中该环境并填写 `app_id`、`api_key`、`group_key`。环境变量 `base_url` **包含 `/api` 且末尾不加斜杠**，集合请求在其后追加 `/order/create` 等路径；本文 `{BASE_URL}` 则不含 `/api`。

先发送普通建单请求，再用保存的订单号执行 POST 查询；同一商户单号的重试复用原币种和原始金额。集合脚本会在发送前展开变量并生成签名，无需手填 `sign`。金额保持字符串，指定渠道时另配 `payment_method_key` 并核对三个回跳域名。

物流与 webhook 请求按实际场景单独执行：`/order/ship` 会覆盖物流并派发同步任务；模拟交易结果 webhook 会向商户接收地址发请求；模拟网关 webhook 则可能改变系统订单状态及账务。联调使用测试订单和测试凭证，按各请求说明填写目标地址与编号。

---

## 外部物流回传协议（附件 v1.1）

附件《发货状态同步 API 接口规范》v1.1（2026-10-26）约定 `POST /api/fulfillment/shipment`，方向是**本系统向商户站点推送物流**。本系统已实现应用级异步回传：在商户应用中开启“物流回传”，填写完整 HTTPS 接口地址（含路径及所需 query 参数），系统直接向该地址发送 POST JSON，不自动拼接协议示例路径。开关默认关闭；使用订单所属应用的 `app_id`、`api_key` 签名，无需额外配置物流凭证。

应用表单可点击回传地址旁的“检测地址”，用当前输入（无需先保存）的完整地址发起 HEAD 请求并显示 HTTP 状态。检测不发送应用凭证或物流数据，也不触发真实回传；站点有响应仅表示连通，签名与发货处理仍需在订单详情手动推送验证。

开启后，批量上传、后台录入、`/order/ship` 以及后续改单均在本地保存后创建回传快照并提交后入队。开启开关不会自动补传历史物流；在后台订单详情，拥有订单查看及物流权限、且能访问该商户订单的用户可点击“推送物流到商户网站”，推送当前物流并查看回传状态和最近 10 次记录。该动作不修改原物流记录。

| 项目 | 本系统 `/api/order/ship` | 附件 `/api/fulfillment/shipment` |
|---|---|---|
| 调用方向 | 商户/ERP → 本系统 | 本系统 → 商户站点 |
| 订单号 | `merchant_order_no`，组合单使用子商户号 | 同一建单时的 `merchant_order_no`，组合单使用父级原商户号，子商户号另放 `child_merchant_order_no` |
| 承运商 | `logistics_company`，系统已启用的承运商代码 | `carrier` 必填自由文本，`carrier_code` 可选 |
| 发货时间 | `shipped_at` 必填，可解析日期，推荐 ISO 8601 | `shipped_at` 可选 ISO 8601，省略取站点收到时间 |
| 多次提交 | 覆盖同一订单的物流记录，不保留多批历史 | 按订单号 + 运单号去重；不同运单号记录新批次并通知客户，同号重试不重复通知 |
| nonce 窗口 | 300 秒内不可重复 | 600 秒内不可重复 |

两者均使用 App-ID、Timestamp、X-Nonce 和 body `sign` 的规范化 HMAC-SHA256 算法，时间戳均允许 ±300 秒；每次实际发送重新生成时间戳、nonce 和签名，重试沿用原物流快照及发货时间。出站传原始 `merchant_order_no`、承运商名称与代码、运单号、已有的查询链接及带时区的 ISO 8601 发货时间。附件支持可选 `items` 描述部分发货，但本系统 `/order/ship` 不接收这组字段，首版回传也不传 `items`；外部记录当前不支持通过接口覆盖或删除。

本地改单仍覆盖物流，并继续回传商户网站；客户通知由商户自行处理。出现新版本后，旧待发或待重试任务停止，只尝试最新物流，避免旧单号在新单号之后再次覆盖商户展示。发送时重新检查应用开关及商户归属，并读取应用当前回传地址和凭证；应用关闭时暂停待发任务。

成功要求 HTTP 200 且响应 JSON `code = 0`。网络异常、超时、HTTP 429/5xx 含首次最多尝试 5 次，失败后的等待间隔依次为 30 秒、5 分钟、30 分钟、1 小时；401/404/422 等其他响应直接终止。`merchant-shipments:process-due` 每分钟投递到期任务、回收两分钟处理超时任务并处理失败提醒，实际重试时间受调度和队列执行时间影响。每次请求和响应单独记录，终败 Telegram 按商户每组最多 5 笔合并发送；发送失败或限流保留待办并冷却 5 分钟后重发，没有启用的机器人时保留失败记录并标记提醒不可用。

支付站点物流出站链路另见 [WordPress 物流同步协议](wordpress/s-system-sync-tracking.md)。其 endpoint、凭证、字段和 `order_shippings.sync_*` 结果继续独立维护；商户网站回传结果写入 `order_shipment_syncs` / `order_shipment_sync_attempts`。

## 组合付款与 V2 通知

### 支付组开关、金额上限与创建

支付组管理提供「允许组合付款」和「组合订单最高金额（USD）」。开关默认关闭；开启时必须填写大于 0、最多两位小数的上限。上限比较整笔订单按实际汇率（含汇损）折算后的 USD 总应付金额，等于上限允许创建，超过上限返回 HTTP 422 / `COMBINE_AMOUNT_LIMIT_EXCEEDED`，不保存父子单、不发起远端支付。缺失或非正数上限不代表无限额，不能创建组合单。普通单仍走原单笔支付规则。

应用不再有组合付款开关，仅保留普通订单的支付通知版本；组合订单始终使用 V2，不受应用 V1/V2 选择影响，也不自动修改应用配置。商户需先完成 V2 验签、进度处理及物流对接，再开启支付组的组合付款。关闭开关或降低上限只影响新组合订单，已有父单可按原总额继续付清，幂等重试仍返回原父单。

所有入口仍复用统一建单服务。当前支付组开启组合付款、未指定 `payment_method_key`，且组内所有启用、商户有权使用的渠道都存在有限单笔上限、订单 USD 总额超过这些上限时，校验支付组的组合金额上限后尝试创建组合单。同一应用下，各支付组独立配置。因日/月余额不足而无法普通付款不会单独触发拆单。指定渠道入口保持原语义，不转为组合单。

组合付款的商品匹配模式仅支持 `MATCH` / `VIRTUAL`，`COPY` / `CREATE` 不能参与任何一笔，包括余额一次付清。若没有模式和额度均合格的组合渠道，返回 `409 COMBINE_PAYMENT_UNAVAILABLE`，不会创建父单。普通单仍可使用 `COPY` / `CREATE`；若普通渠道可承接全额，仍走原普通下单规则。

建单返回 `order_type=single|combine`。组合单只创建父记录，保存商品/客户/回跳信息和总金额；不创建子单草稿，不预建远端支付订单。商户原号仍最多 64 字符，实际子单追加 `-S001`、`-S002` 等后缀；相应命名空间不可被其他新订单占用。幂等按商户和原号判断，币种/原请求金额不同返回冲突。

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "order_type": "combine",
    "order_no": "CMB01JEXAMPLEPARENT00000000000",
    "merchant_order_no": "M20261113001",
    "status": "pending",
    "order_status": "pending",
    "platform": "wordpress",
    "currency": "USD",
    "amount": "4000.00",
    "converted_currency": "USD",
    "converted_amount": "4000.00",
    "exchange_rate": "1.000000",
    "paid_amount": "0.00",
    "paid_converted_amount": "0.00",
    "remaining_amount": "4000.00",
    "remaining_converted_amount": "4000.00",
    "payment_method": null,
    "settlement_status": null,
    "shipping_status": "unshipped",
    "current_order_no": null,
    "version": 0,
    "children": [],
    "created_at": "2026-11-13T11:55:00+08:00",
    "paid_at": null,
    "payment_link_token": "PUBLIC_PAYMENT_TOKEN",
    "payment_url": "https://pay.example.com/combine-payment/PUBLIC_PAYMENT_TOKEN",
    "pay_url": "https://pay.example.com/combine-payment/PUBLIC_PAYMENT_TOKEN"
  }
}
```

组合单的 `payment_url` 和 `pay_url` 都是独立收银台，商户只需引导客户进入此地址。收银台保存短期报价，只有点击付款才创建当前真实子单及远端订单；刷新/重复点击沿用同一笔。未来笔数和金额不预定。发送付款邮件时只发送父单收银台。

组合收银台仅提供英文界面，不再提供语言切换。每个子单支付后的回跳仍返回组合收银台；全部付清后显示 “Your order has been paid in full.”，不显示返回商户链接，也不自动跳转到商户 `return_url`。商户通过 webhook 和查询确认原订单状态。

### 每轮金额与渠道

1. 先排除 `COPY` / `CREATE`，仅允许 `MATCH` / `VIRTUAL`。优先沿用当前已选报价的渠道；没有报价时沿用最近一笔已付款子单的渠道。原渠道可收清余额时直接收清，否则继续按该渠道单笔上限的 70%～90% 拆付；不因其他渠道限额更高或能一次收清就切换。
2. 首笔尚未选定渠道，或原渠道停用、删除、授权/原组关系失效、商品模式不支持、额度/笔数/手续费不满足本笔规则时，才重新选择。重新选择仍先找可一次收清余额的渠道，沿用组内负载/权重规则；否则候选日/月余额均须容纳完整单笔上限 U，且日笔数、手续费合格。不限单笔上限的渠道不参加百分比随机。
3. 需要重新选择且无法一次收清时，按 U 降序取最高三个候选，等概率随机选择一个；金额仍从已选渠道 U 的 70%～90% 区间随机取原币整数分，USD 校验也在此区间。报价过期只重新计算有效报价，原渠道仍合格就继续使用；切换到新渠道后不会仅因旧渠道恢复而切回。
4. 父单只应用一次自动折扣、一次汇率/汇损快照。第 i 笔 USD 金额为「累计原币已付金额 × 锁定汇率截断两位」减去之前累计 USD 已付；最后一笔收清两种币种余额，避免每笔独立舍入累积尾差。金额全部使用十进制字符串和 bcmath。
5. 每次真实建单在商户、父单和渠道锁内复查商品模式和额度；报价后改为 `COPY` / `CREATE` 时旧报价失效。已有未决子单也不能通过补建或继续付款绕过模式限制，原子单和占用保留，不自动换渠道。所有新订单的未决支付占用日/月金额及笔数，支付成功后改为成交统计。历史未决订单不自动回填占用。
6. 当前子单确认收款后，才开放下一笔，优先沿用原渠道；仅原渠道不再满足条件时可切换到本支付组内其他可用渠道。不能创建多个同时可付的子单；不预留未来全部额度。中途无渠道时保留进度，恢复后重试。

远端超时/错误保留原子单、渠道、金额与远端引用，只能重试同一笔或通过后台「查询最新状态」核对。不能把网络失败当作未付款后换号收取。收到明确取消/过期、金额或币种不符、退款/拒付/争议时进入核对流程；不会自行创建替代子单，也不自动退款。

父单付款截止时间沿用付款链接有效期。到期停止新付款，保留未决子单的额度占用；有效迟到成功仍记账并通知，若该笔收清余额则可以完成，否则父单保持到期。部分付款资金先进入原子单账务，父单不重复入账；只有父单付清才允许子单到期结算到可提现。广告转化仅在父单付清时按原订单总额触发一次。

### 查询返回

原号 / 父系统号查询父单；带后缀的商户号 / 子系统号查询真实子单，并返回 `parent_order_no`、`parent_merchant_order_no`。同时传两个编号仍使用 AND，所有接口显式限定当前商户。

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "order_type": "combine",
    "order_no": "CMB01JEXAMPLEPARENT00000000000",
    "merchant_order_no": "M20261113001",
    "status": "pending",
    "order_status": "partially_paid",
    "platform": "wordpress",
    "currency": "USD",
    "amount": "4000.00",
    "converted_currency": "USD",
    "converted_amount": "4000.00",
    "exchange_rate": "1.000000",
    "paid_amount": "640.00",
    "paid_converted_amount": "640.00",
    "remaining_amount": "3360.00",
    "remaining_converted_amount": "3360.00",
    "payment_method": null,
    "settlement_status": null,
    "shipping_status": "unshipped",
    "current_order_no": null,
    "version": 1,
    "children": [
      {
        "sequence": 1,
        "order_no": "ORD202611130001",
        "merchant_order_no": "M20261113001-S001",
        "status": "paid",
        "order_status": "pending_settlement",
        "settlement_status": "pending",
        "shipping_status": "unshipped",
        "payment_status": "paid",
        "currency": "USD",
        "amount": "640.00",
        "converted_currency": "USD",
        "converted_amount": "640.00",
        "payment_method": "example_channel",
        "paid_at": "2026-11-13T12:00:00+08:00",
        "logistics": null
      }
    ],
    "created_at": "2026-11-13T11:55:00+08:00",
    "paid_at": null
  }
}
```

- `amount / converted_amount` 始终为父单应付总额。`paid_amount / paid_converted_amount` 为累计原币 / USD 已收毛额，不是扣手续费后的净入账额，也不因后续退款反向减少；退款/争议由子单 `order_status` 与父单 `review` 表示。
- `remaining_amount / remaining_converted_amount` 为尚未收取金额。
- 部分付款：`status=pending`、`order_status=partially_paid`；全部付清：`status=paid`。到期或待核对分别为 `expired` / `review`；不凭已收到某笔子单款发货。
- `children` 仅包含已创建的真实子单，无未来付款占位。已付子单 `payment_status=paid`；尚未完成的真实子单保留实际 pending 等状态。退款/拒付请看 `order_status`。
- 子单 `logistics` 未上传为 null，否则含公司、单号、追踪链接、发货时间和远端 `sync_status`。父单 `shipping_status` 为 unshipped / partially_shipped / shipped。
- `version` 是已确认付款的递增版本，不是所有售后状态的版本。查询只读取数据库，不主动向网关补查。
- 父单 `payment_method`、`settlement_status` 为 null；实际渠道和结算状态分别在子单内。

### 每笔付款的 webhook

组合父单的每个真实子单首次确认成功后生成一个事件，事件快照和付款状态在同一事务内保存，提交后排队投递。顶层使用父单号、原商户号和父单总额；`paid_order_no` 指明本次新增付款。部分付款发送如下通知：

```json
{
  "order_type": "combine",
  "order_no": "CMB01JEXAMPLEPARENT00000000000",
  "merchant_order_no": "M20261113001",
  "status": "pending",
  "order_status": "partially_paid",
  "platform": "wordpress",
  "currency": "USD",
  "amount": "4000.00",
  "converted_currency": "USD",
  "converted_amount": "4000.00",
  "exchange_rate": "1.000000",
  "paid_amount": "640.00",
  "paid_converted_amount": "640.00",
  "remaining_amount": "3360.00",
  "remaining_converted_amount": "3360.00",
  "payment_method": null,
  "settlement_status": null,
  "shipping_status": "unshipped",
  "current_order_no": null,
  "version": 1,
  "children": [
    {
      "sequence": 1,
      "order_no": "ORD202611130001",
      "merchant_order_no": "M20261113001-S001",
      "status": "paid",
      "order_status": "pending_settlement",
      "settlement_status": null,
      "shipping_status": "unshipped",
      "payment_status": "paid",
      "currency": "USD",
      "amount": "640.00",
      "converted_currency": "USD",
      "converted_amount": "640.00",
      "payment_method": "example_channel",
      "paid_at": "2026-11-13T12:00:00+08:00",
      "logistics": null
    }
  ],
  "created_at": "2026-11-13T11:55:00+08:00",
  "paid_at": null,
  "event_id": "bc7c186f-a106-4ec7-ad42-1bea374be103",
  "paid_order_no": "ORD202611130001",
  "occurred_at": "2026-11-13T12:00:01+08:00",
  "sign": "SIGNATURE_PLACEHOLDER"
}
```

最后一笔通知将 `status / order_status` 设为 paid，累计金额等于总额，两个 remaining 字段为 0.00，`paid_at` 为完成时间，children 包含所有实际子单。V2 普通单保留原字段并增加 `order_type=single`、`event_id`、`version=1`、`paid_order_no`、累计金额、余额为零和 `children=[]`。

**验签算法不变，但新增字段必然改变签名原文。** 对完整收到的 body 去掉 sign，再用原 SignatureCanonicalizer 规则递归规范化、HMAC；children、null 和金额字符串全部参与，不能只选旧字段验签，也不能先把金额字符串转成浮点数。

按 `event_id` 去重，按父单 `version` 只前进不回退；不要用「父单号 + pending」去重不同子单事件。一次事件的重试保持原快照与 event_id，Timestamp/X-Nonce/sign 每次刷新。网络重试可能乱序，较小版本直接确认接收并忽略其旧进度。只有经过验证的父单 `status=paid` 才将商户原订单标为成功。HTTP 2xx 应答和原重试间隔保持。

### 商户物流上传与回传

父单全部付清后，按每笔真实子单号分别调用现有 `POST /order/ship`；上传号上限扩为 80 字符。请求金额、签名和承运商校验规则保持：

```json
{
  "merchant_order_no": "M20261113001-S001",
  "logistics_company": "DHL",
  "tracking_number": "REAL-TRACKING-001",
  "shipped_at": "2026-11-14T12:00:00+08:00",
  "sign": "SIGNATURE_PLACEHOLDER"
}
```

成功响应保留原物流字段并增加 `order_type=combine`、`parent_order_no`、`parent_merchant_order_no` 与 `sync_status`。每个子单通过自己的渠道同步物流；父单不写物流记录。

**商户网站物流回传使用父级原商户订单号。** 回传的 `merchant_order_no` 为父单原号，便于商户直接定位原订单；新增 `child_merchant_order_no` 保留本次子商户号。`order_no` 仍为子单系统号，`parent_order_no` / `parent_merchant_order_no` 继续携带父单引用。例如：

```json
{
  "merchant_order_no": "M20261113001",
  "child_merchant_order_no": "M20261113001-S001",
  "order_type": "combine",
  "order_no": "ORD202611130001",
  "parent_order_no": "CMB202611130001",
  "parent_merchant_order_no": "M20261113001",
  "carrier": "DHL",
  "carrier_code": "DHL",
  "tracking_number": "REAL-TRACKING-001",
  "shipped_at": "2026-11-14T12:00:00+08:00",
  "sign": "SIGNATURE_PLACEHOLDER"
}
```

每个子单仍分别回传；接收方按 `merchant_order_no + tracking_number` 去重，同一父单的多个子单共用真实运单时只记录一次发货、避免重复通知客户。新增字段参与完整通知体验签。普通订单回传保持原格式；商户上传 `/order/ship` 仍使用子商户号。调整前已保存的回传快照保持原样，如需使用新格式，可在订单详情手动推送生成新快照，并停止该子单旧的待发或重试任务。

| HTTP | 错误码 | 处理 |
|---|---|---|
| 409 | COMBINE_PAYMENT_UNAVAILABLE | 目前没有满足拆单条件的渠道，保留已付款进度，稍后重试 |
| 422 | COMBINE_AMOUNT_LIMIT_EXCEEDED | 整笔订单折算 USD 总额超过支付组的组合付款上限，调整订单或支付组配置后重试 |
| 409 | CHILD_NUMBER_CONFLICT | 父子号命名空间冲突，建单时换一个原商户订单号 |
| 422 | CHILD_ORDER_REQUIRED | 将父单原号换成真实子商户号 |
| 409 | COMBINE_ORDER_NOT_PAID | 等待原组合订单全部付清 |
| 409 | QUOTE_EXPIRED | 收银台重新报价，确认新金额后付款 |
| 409 | COMBINE_NOT_PAYABLE / COMBINE_REVIEW_REQUIRED | 停止继续付款，核对状态及已收款 |

一个真实包裹涉及多笔子单时，逐笔提交相同的真实物流号。现有[支付插件物流协议](wordpress/s-system-sync-tracking.md)对跨订单重复运单有限制：插件须支持同一真实运单关联多个订单，否则相应子单显示同步失败，不能承诺全部成功。禁止伪造带 -S001 的运单号。本仓库不包含插件代码，未修改插件去重规则。

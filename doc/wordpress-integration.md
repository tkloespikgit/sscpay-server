# WordPress 插件对接文档（订单系统 API）

> 基于当前系统代码整理（路由、鉴权中间件、控制器、回调服务），供开发 WordPress 插件（如 WooCommerce 支付网关）使用。

## 对接总览

```
WordPress 站点（插件）                        订单系统
─────────────────────                    ─────────────────
1. 用户下单/结账
   └─ POST /api/order/create  ──────────►  创建订单，锁定支付方式
   ◄────────────────────────  返回 payment_url
2. 把用户重定向到 payment_url  ──────────►  托管收银页，用户完成支付
3. 支付结果产生
   ◄──── POST notify_url（webhook）──────  交易结果异步通知（带签名）
   └─ 验签 → 更新订单状态 → 返回 2xx
4. 用户浏览器跳回 return_url / cancel_url（可选，仅展示用途，不能作为状态依据）
5. 兜底：主动调 GET /api/order/query 查询订单最终状态
```

**核心原则**：订单状态以 webhook 通知 + 主动查询 为准，`return_url` 跳转只是用户体验，不可信。

### 接入前需要拿到的配置

| 配置项 | 来源 | 插件设置项建议 |
|---|---|---|
| `API Base URL` | 订单系统部署地址（如 `https://pay.example.com`） | 文本框 |
| `App-ID` | 订单系统后台「应用管理」创建应用生成 | 文本框 |
| `API Key` | 同上（用于签名请求 + 验证回调签名） | 密码框 |
| `group_key` | 订单系统后台「支付组」配置的支付组标识 | 文本框 |
| `payment_method_key` | 可选。订单系统后台「支付方式」的 `method_code`，仅当插件运行在该支付方式绑定的站点上时才传 | 文本框（可空） |
| 回调域名白名单 | 需让订单系统超级管理员把 WordPress 站点域名加入该商户的白名单 | ——（后台操作） |

> ⚠️ `notify_url` / `return_url` / `cancel_url` 的域名必须先在商户白名单里，否则下单直接被拒（`CALLBACK_DOMAIN_NOT_ALLOWED`）。

---

## 鉴权与签名（所有出站请求通用）

### 请求结构

***Header 请求头***（3 个，缺一不可）：

| 键              | 说明 |
|----------------|---|
| `App-ID`       | 应用的 `app_id` |
| `Timestamp`    | 当前 Unix 时间戳（秒），允许 ±5 分钟 |
| `X-Nonce`      | 随机字符串（如 `bin2hex(random_bytes(8))`），5 分钟内不可重复 |
| `Content-Type` | `application/json` |

签名放在请求体的 `sign` 字段里，不是 Header。

### 签名算法

```
StringToSign = App-ID + "\n" + Timestamp + "\n" + X-Nonce + "\n" + 规范化请求体(不含 sign)
Signature    = HMAC-SHA256(StringToSign, api_key)   // 小写 hex
```

规范化步骤（逐字节敏感，是最容易踩坑的地方）：
1. 从请求体移除 `sign` 字段
2. 关联数组（对象）按 key 递归排序（ksort）；数字索引列表（如 `items`）保持原顺序，不排序
3. `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`。

```php
function ksort_recursive($value) {
    if (! is_array($value)) {
        return $value;
    }
    $is_list = array_is_list($value);
    $result = [];
    foreach ($value as $k => $v) {
        $result[$k] = ksort_recursive($v);
    }
    if (! $is_list) {
        ksort($result);
    }
    return $result;
}

function canonicalize(array $body): string {
    unset($body['sign']);
    return json_encode(ksort_recursive($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$sign = hash_hmac('sha256',
    $app_id . "\n" . $timestamp . "\n" . $nonce . "\n" . canonicalize($body),
    $api_key
);
$body['sign'] = $sign; // 加回请求体再发送
```

> 金额字段一律以字符串传递（如 `"190.00"` 而不是 `190.00`），避免浮点序列化差异导致签名/金额校验失败。

---

## 创建订单

```
POST {BASE_URL}/api/order/create
```

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
      "product_name": "Mechanical Keyboard",
      "product_description": "Blue switches, RGB backlight",
      "unit_price": "90.00",
      "quantity": 2
    }
  ],
  "notify_url": "https://your-wp-site.com/wp-json/your-plugin/v1/webhook",
  "return_url": "https://your-wp-site.com/checkout/order-received/123/",
  "cancel_url": "https://your-wp-site.com/cart/",
  "sign": "…"
}
```

### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `merchant_order_no` | string ≤64 | ✅ | 商户订单号（建议用 WooCommerce 订单号，如 `WC-1234`）。幂等键：重复提交返回已有订单，不会重复创建 |
| `platform` | string ≤50 | ✅ | 电商网站平台类型枚举，WordPress 站点固定传 `wordpress`；合法值列表由服务端系统配置 `order.platforms` 维护 |
| `currency` | string(3) | ✅ | 如 `EUR`/`JPY`/`GBP`，须在系统支持的币种列表内 |
| `group_key` | string ≤50 | ✅ | 支付组标识（传了 `payment_method_key` 也仍然必填） |
| `payment_method_key` | string ≤50 | 否 | 指定支付渠道（= 后台支付方式的 `method_code`）。传了就**跳过支付组路由与单笔/日/月限额风控**，直接用该渠道；代价是下面三个回跳地址全部必填且域名必须与该渠道绑定的站点域名一致，否则 `PAYMENT_METHOD_DOMAIN_MISMATCH` 拒单 |
| `subtotal` | string | ✅ | 商品小计 = Σ(`unit_price × quantity`) |
| `shipping_fee` | string | ✅ | 运费 |
| `discount` | string | ✅ | 折扣（正数表示减免） |
| `tax` | string | ✅ | 税金 |
| `amount` | string | ✅ | 总额，必须严格等于 `subtotal + shipping_fee - discount + tax`（误差 ≤0.01，否则拒单） |
| `customer.*` | string | ✅ | `first_name` / `last_name` / `email` / `phone` |
| `shipping_address.line1` | string ≤255 | ✅ | 地址行1 |
| `shipping_address.line2` | string ≤255 | 否 | 地址行2 |
| `shipping_address.city` | string ≤100 | ✅ | 城市 |
| `shipping_address.state` | string ≤100 | 否 | 州/省 |
| `shipping_address.country` | string(2) | ✅ | ISO 3166-1 alpha-2（`DE`、`US`） |
| `shipping_address.zip` | string ≤20 | ✅ | 邮编 |
| `items` | array ≥1 | ✅ | 商品明细 |
| `items[].product_sku` | string ≤64 | 否 | 商品编号 |
| `items[].product_name` | string ≤255 | ✅ | 商品名称 |
| `items[].product_description` | string | 否 | 商品描述 |
| `items[].unit_price` | string | ✅ | 单价 |
| `items[].quantity` | integer ≥1 | ✅ | 数量 |
| `notify_url` | url ≤500 | 条件必填 | 异步回调地址，域名须在白名单内；**传了 `payment_method_key` 时必填**且域名须与该渠道绑定站点一致 |
| `return_url` | url ≤500 | 条件必填 | 支付成功跳转地址，同上 |
| `cancel_url` | url ≤500 | 条件必填 | 取消/失败跳转地址，同上 |
| `sign` | string | ✅ | 见第 2 节 |

### 成功响应（HTTP 200）

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "order_no": "ORD2026070612345678",
    "payment_link_token": "PL_AbCdEf123456_20260706123456",
    "payment_url": "https://pay.example.com/payment/PL_AbCdEf123456_20260706123456",
    "converted_currency": "USD",
    "converted_amount": "205.20",
    "exchange_rate": "1.080000",
    "surcharge_percent": "1.5000",
    "surcharge_fee": "2.85",
    "payment_method": "paypal",
    "status": "pending"
  }
}
```

| 字段 | 插件应处理 |
|---|---|
| `order_no` | 存入订单元数据（`_order_system_order_no`） |
| `payment_url` | 把用户重定向到这里完成支付（托管收银页）。支付链接有有效期（系统默认 7 天） |
| `payment_method` | 系统在下单时已锁死的唯一支付方式，没有候选列表，无需让用户二次选择 |
| 汇率/汇损字段 | 可选，展示给用户或存入元数据备查 |

### 错误响应

```json
{ "code": 422, "msg": "Amount mismatch: expected 190.00, got 999.99", "error_code": "AMOUNT_MISMATCH" }
```

| HTTP | `error_code` | 触发条件 |
|---|---|---|
| 401 | — | 鉴权失败：参数缺失、时间戳过期（>5 分钟）、Nonce 重复、App-ID 无效/禁用、签名错误 |
| 422 | （无 `error_code`，标准验证错误，`errors` 字段含具体字段错误） | 字段格式问题（必填缺失、类型错、超长） |
| 422 | `AMOUNT_MISMATCH` | `amount` 与公式结果差超过 0.01 |
| 422 | `ITEMS_SUBTOTAL_MISMATCH` | `subtotal` 与明细之和不符 |
| 422 | `CALLBACK_DOMAIN_NOT_ALLOWED` | 回调/跳转地址域名不在白名单 |
| 422 | `PAYMENT_METHOD_NOT_AVAILABLE` | 指定的 `payment_method_key` 在该商户名下不存在或已停用 |
| 422 | `PAYMENT_METHOD_DOMAIN_MISMATCH` | 指定 `payment_method_key` 时三个回跳地址缺失，或域名与该渠道绑定的站点域名不一致 |
| 409 | `NO_AVAILABLE_PAYMENT_METHOD` | 支付组内所有支付方式都被风控拦截，或支付组不存在/未启用（不会创建订单；仅在未指定 `payment_method_key` 时出现） |

### 幂等性与重试

`merchant_order_no` 是幂等键：重复提交原样返回已有订单，不会重新算汇率或走风控。超时重试时务必复用同一个 `merchant_order_no`（以及重新生成 `Timestamp` / `X-Nonce` / `sign`）。

---

## 订单查询（兜底）

```
GET {BASE_URL}/api/order/query?order_no={order_no}
GET {BASE_URL}/api/order/query?merchant_order_no={merchant_order_no}
```

鉴权同第 2 节（查询接口的请求体只有参数，`sign` 字段对 query string 计算——建议请求体传 `{"order_no": "...", "sign": "..."}` 或按服务端约定把查询参数放进签名体，开发前先与服务端确认；当前实现从 `$request->json()` 取 `sign`，即查询也需要 JSON body）。

> 实现提示：GET 请求也带 JSON body（含 `order_no`/`merchant_order_no` 与 `sign`），签名对 body 计算。若遇到问题以服务端联调为准。

### 响应

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

订单不存在：`404` + `error_code: ORDER_NOT_FOUND`。

插件用途：用户从支付页返回（`return_url`）时，主动查一次最新状态，避免漏收通知；或做定时对账（WP-Cron）。

---

## Webhook 回调（交易结果通知）

订单状态变化时，系统会 `POST` JSON 到创建订单时传的 `notify_url`。

### Payload

```json
{
  "amount": "190.00",
  "converted_amount": "205.20",
  "converted_currency": "USD",
  "currency": "EUR",
  "merchant_order_no": "M20260706001",
  "occurred_at": "2026-07-06T12:40:00+00:00",
  "order_no": "ORD2026070612345678",
  "payment_method": "paypal",
  "status": "paid",
  "sign": "…"
}
```

### 验签（插件必须实现）

服务端算法：对 `sign` 以外的全部字段做 `ksort`（仅顶层，非递归），`json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`，再用 api_key 做 `HMAC-SHA256`：

```php
$payload = json_decode(file_get_contents('php://input'), true);
$sign = $payload['sign'] ?? '';
unset($payload['sign']);
ksort($payload); // 注意：这里只需顶层 ksort（payload 是扁平结构）
$expected = hash_hmac(
    'sha256',
    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    $api_key
);
if (! hash_equals($expected, $sign)) {
    status_header(401); // 验签失败直接拒绝
    exit;
}
```

> 出站请求的签名（第 2 节）与回调验签的规范化略有差异：出站是「递归排序 + 三段 StringToSign」，回调是「扁平 ksort + 直接对 JSON 签名」。两边都要实现。

### 响应要求与重试机制

- 插件返回 2xx 视为通知成功；其他状态码/超时/连接失败视为失败。
- 失败自动重试，最多 5 次，间隔 30 秒 → 5 分钟 → 30 分钟 → 1 小时，5 次后放弃。
- 因此插件的 webhook 处理必须幂等（按 `order_no` + `status` 判断，已处理过的状态直接返回 200）。

### 订单状态机（插件需映射到 WooCommerce 状态）

| 系统状态 | 含义 | 建议 WooCommerce 映射 |
|---|---|---|
| `pending` | 待支付 | `pending` |
| `paid` | 已支付 | `processing` |
| `shipped` | 已发货 | `completed`（或保留 processing） |
| `completed` | 已完成 | `completed` |
| `partially_refunded` | 部分退款 | `partially-refunded` |
| `refunded` | 全额退款 | `refunded` |
| `chargeback` | 拒付 | `failed`（并邮件提醒管理员） |
| `cancelled` | 已取消 | `cancelled` |

---

## WordPress 插件设计建议

### 模块划分

1. 设置页：用 `Settings API` 或 WooCommerce Payment Gateway 自带的设置表单，配置 Base URL、App-ID、API Key、Group Key、启用开关。
2. API Client 类：封装签名（第 2 节）、`create_order()`、`query_order()`，用 `wp_remote_post()` / `wp_remote_get()`，超时建议 15s。
3. Webhook 端点：注册 REST 路由 `wp-json/{plugin}/v1/webhook`（`permission_callback => '__return_true'`，安全性靠验签保证）；或 rewrite 规则 `?rest_route=`。处理流程：读原始 body → 验签 → 幂等检查 → 更新订单 → 返回 200。
4. 返回处理：`return_url` 落地页上调用 `query_order()` 展示真实状态。
5. 对账（可选）：WP-Cron 定时用 `query_order()` 校对长时间 `pending` 的订单。

### WooCommerce 集成要点

- 实现 `WC_Payment_Gateway` 子类，`process_payment()` 里：组装请求（订单号用 `WC-{$order_id}` 或订单号）→ 调 `create_order()` → 保存 `order_no`/`payment_url` 到 order meta → `return ['result' => 'success', 'redirect' => $payment_url]`。
- `notify_url` 传站点 REST webhook 地址；`return_url` 传 `$order->get_checkout_order_received_url()`；`cancel_url` 传 `$order->get_cancel_order_url()` 或购物车。
- 金额组装：`subtotal` = Σ(单价×数量)、`shipping_fee` = 运费、`discount` = 总折扣、`tax` = 总税、`amount` = 订单总价；全部 `number_format($x, 2, '.', '')` 转字符串，提交前自检 `amount` 公式。
- WooCommerce 折扣若含在单价里，注意 `unit_price` 用折后价，保持与 `subtotal` 自洽。

### 常见坑清单

1. 签名规范化必须逐字节一致：递归排序规则、`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`、不含 `sign` 字段。建议先用最简请求体调通签名再上完整流程。
2. 金额一律字符串；注意不同 locale 下 `number_format` 的千分位符。
3. 回调域名白名单要提前配置，否则 `CALLBACK_DOMAIN_NOT_ALLOWED`。
4. Webhook 处理要快（系统侧超时 10 秒），不要在回调里做耗时操作，用 `wp_schedule_single_event` 异步处理重活。
5. 服务器时钟要同步（时间戳误差 >5 分钟直接 401）。
6. `payment_method` 是锁定的单一值，插件不需要提供支付方式选择界面。
7. `payment_method_key` 只适合"插件就装在该支付方式绑定的那个站点上"的场景（同站点直连）：它能绕过限额风控，但 `notify_url` / `return_url` / `cancel_url` 必须全部传齐且域名与后台配置的「网站域名」一致（忽略大小写、`www.` 前缀与端口）。多站点共用一个插件时不要写死这个值，否则其他站点下单会全部被 `PAYMENT_METHOD_DOMAIN_MISMATCH` 拒单。

---

## 附：接口速查

| 用途 | 方法 & 路径 | 鉴权 |
|---|---|---|
| 创建订单 | `POST /api/order/create` | Header 三件套 + body `sign` |
| 查询订单 | `GET /api/order/query` | 同上 |
| 托管收银页 | `GET /payment/{payment_link_token}` | 无需（公开，有有效期，默认 7 天） |
| 交易结果回调 | 系统 → `notify_url` | payload 内 `sign`（api_key 验签） |

# 创建订单 API

```
POST /order/create
```

对外下单接口。商户系统调用这个接口在多商户订单系统里创建一笔订单。

---

## 鉴权

所有请求必须携带以下 3 个 Header:

| Header | 说明 |
|---|---|
| `App-ID` | 应用的 `app_id`（在系统后台"应用管理"里创建应用后自动生成） |
| `Timestamp` | 当前 Unix 时间戳（秒级），允许 ±5 分钟误差，超过视为过期请求 |
| `X-Nonce` | 随机字符串，防重放攻击。5 分钟内不可重复使用 |

签名放在**请求体的 `sign` 字段里**（不是 Header）。

### 签名算法

```
StringToSign = App-ID + "\n" + Timestamp + "\n" + X-Nonce + "\n" + 规范化请求体

Signature = HMAC-SHA256(StringToSign, api_key)
```

**"规范化请求体"是这里唯一的难点**，因为 `sign` 字段本身在请求体里，不能直接对"包含 sign 的原始 JSON 字符串"取值。规范化步骤：

1. 从请求体里**移除** `sign` 字段。
2. 对所有**关联数组（对象）**按 key 做**递归排序**；**数字索引的列表**（比如 `items` 数组）**保持原顺序**，不做排序。
3. 用规范化后的结果重新编码成 JSON 字符串（编码时不转义 Unicode 字符、不转义斜杠）。

计算出签名后，把 `sign` 字段加回请求体里再发送。

> ⚠️ 这一步最容易出错。如果两边（商户端和服务端）对"排序规则"或"JSON 编码方式"的理解有一丝偏差，签名就永远对不上，且只会收到笼统的"签名验证失败"，不会告诉你具体哪里错了。建议先用一个只有一两个字段的简单请求体调通签名逻辑，再对接完整的下单流程。

### 签名示例（PHP）

```php
$appId = 'APP_XXXXXXXXXXXX';
$apiKey = 'your_api_key';
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(8));

$body = [
    'merchant_order_no' => 'M20260706001',
    'currency' => 'EUR',
    'group_key' => 'group_default',
    // ...其余字段，见下方请求体说明
];

// 规范化：关联数组递归按 key 排序，列表保持原顺序
function ksortRecursive($value) {
    if (is_array($value) && array_is_list($value)) {
        return array_map('ksortRecursive', $value);
    }
    if (is_array($value)) {
        $sorted = [];
        foreach ($value as $k => $v) {
            $sorted[$k] = ksortRecursive($v);
        }
        ksort($sorted);
        return $sorted;
    }
    return $value;
}

$canonical = json_encode(ksortRecursive($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stringToSign = $appId . "\n" . $timestamp . "\n" . $nonce . "\n" . $canonical;
$sign = hash_hmac('sha256', $stringToSign, $apiKey);

$body['sign'] = $sign;

// 发送请求
$ch = curl_init('https://your-domain.com/order/create');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'App-ID: ' . $appId,
    'Timestamp: ' . $timestamp,
    'X-Nonce: ' . $nonce,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
```

---

## 请求体

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
      "product_id": "WP-1001",
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
  "sign": "（按上面的算法计算出的签名，追加在最后）"
}
```

### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `merchant_order_no` | string ≤64 | ✅ | 商户自己的订单号。与商户账号组合唯一，重复提交会幂等返回已存在的订单，**不会**重复创建 |
| `platform` | string ≤50 | ✅ | 电商网站平台类型枚举，如 `wordpress` / `shopyy` / `shopline` / `invoice` / `opencart`，合法值列表由系统配置 `order.platforms`（JSON 数组）动态维护，不在列表内直接拒单 |
| `currency` | string(3) | ✅ | 下单币种，如 `EUR`/`JPY`/`GBP`，必须在系统配置的支持币种列表内 |
| `group_key` | string ≤50 | ✅ | 支付方式组标识，在系统后台"支付组"配置。即使传了 `payment_method_key` 也仍然必填（用于校验归属并记录到订单上） |
| `payment_method_key` | string ≤50 | 否 | **指定支付渠道**，取值为系统后台"支付方式"里的 `method_code`（如 `paypal`）。传了就跳过支付组内的路由分配与限额风控，直接用该渠道收款，详见下方[「指定支付渠道」](#指定支付渠道可选) |
| `subtotal` | string(数字) | ✅ | 商品小计，必须等于 `items[].unit_price × items[].quantity` 之和 |
| `shipping_fee` | string(数字) | ✅ | 运费 |
| `discount` | string(数字) | ✅ | 折扣（正数表示减免） |
| `tax` | string(数字) | ✅ | 税金 |
| `amount` | string(数字) | ✅ | 应付总额，**必须严格等于** `subtotal + shipping_fee - discount + tax`，误差超过 0.01 直接拒单 |
| `customer.first_name` / `last_name` / `email` / `phone` | string | ✅ | 客户信息 |
| `shipping_address.line1` | string ≤255 | ✅ | 地址行1 |
| `shipping_address.line2` | string ≤255 | 否 | 地址行2 |
| `shipping_address.city` | string ≤100 | ✅ | 城市 |
| `shipping_address.state` | string ≤100 | 否 | 州/省 |
| `shipping_address.country` | string(2) | ✅ | 国家代码，ISO 3166-1 alpha-2（如 `DE`、`US`） |
| `shipping_address.zip` | string ≤20 | ✅ | 邮编 |
| `items` | array | ✅ | 商品明细，至少 1 项 |
| `items[].product_sku` | string ≤64 | 否 | 商户侧商品编号 |
| `items[].product_id` | string ≤64 | ✅ | 商户侧商品唯一标识（如站点商品 ID） |
| `items[].product_url` | string(url) ≤500 | ✅ | 商品详情页链接 |
| `items[].product_name` | string ≤255 | ✅ | 商品名称 |
| `items[].product_description` | string | 否 | 商品描述 |
| `items[].unit_price` | string(数字) | ✅ | 单价 |
| `items[].quantity` | integer ≥1 | ✅ | 数量 |
| `notify_url` | string(url) ≤500 | 条件必填 | 交易结果异步回调地址。域名必须在该商户的回调域名白名单内，否则拒单；**传了 `payment_method_key` 时本字段必填**，且域名还必须与该渠道绑定的电商网站域名一致 |
| `return_url` | string(url) ≤500 | 条件必填 | 支付成功后跳转地址（同上：需在商户白名单内；指定渠道时必填且必须与渠道绑定域名一致） |
| `cancel_url` | string(url) ≤500 | 条件必填 | 取消/失败跳转地址（同上） |
| `sign` | string | ✅ | 见上方签名算法 |

金额、单价类字段建议**始终以字符串形式传递**（如 `"190.00"` 而不是 `190.00`），避免不同语言/客户端的浮点数序列化差异导致签名对不上。

### 指定支付渠道（可选）

默认情况下，系统会在 `group_key` 对应的支付组里按各渠道权重与风控阈值（单笔/当日金额/当日笔数/当月金额上限）自动锁定一个支付方式。

如果商户端已经确定要用哪个渠道（典型场景：下单请求就来自该渠道绑定的电商网站本身），可以额外传 `payment_method_key`：

```json
{
  "merchant_order_no": "M20260706001",
  "platform": "wordpress",
  "currency": "EUR",
  "group_key": "group_default",
  "payment_method_key": "paypal",
  "notify_url": "https://shop.example.com/wp-json/my-plugin/v1/webhook",
  "return_url": "https://shop.example.com/checkout/order-received/123/",
  "cancel_url": "https://shop.example.com/cart/"
}
```

传了 `payment_method_key` 之后的行为变化：

| 环节 | 默认（不传） | 指定渠道（传了） |
|---|---|---|
| 渠道选择 | 支付组内按权重加权均匀分配 | **直接用指定的渠道**，不再在组内查找匹配 |
| 限额风控 | 校验单笔/当日金额/当日笔数/当月金额阈值，全部不通过则拒单 | **不再校验这些阈值**，直接建单 |
| `group_key` | 必填 | 仍然必填（校验支付组存在且启用，并记录到订单上；不要求该渠道一定挂在这个组里） |
| 三个回跳地址 | 可选 | **全部必填**，且域名必须与该渠道绑定的电商网站域名（后台"支付方式 → 网站域名"）一致，否则拒单 |
| 商户回调域名白名单 | 校验 | 仍然校验（两道关卡叠加） |

域名比较忽略大小写、`www.` 前缀与端口号，即 `https://www.shop.example.com/cart` 与绑定域名 `https://shop.example.com` 视为同一站点；子域名不同（如 `merchant.example.com` vs `shop.example.com`）则视为不匹配。

---

## 响应

### 成功

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "order_no": "ORD2026070612345678",
    "payment_link_token": "PL_AbCdEf123456_20260706123456",
    "payment_url": "https://your-domain.com/payment/PL_AbCdEf123456_20260706123456",
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

| 字段 | 说明 |
|---|---|
| `order_no` | 系统订单号 |
| `payment_link_token` / `payment_url` | 引导客户完成支付的托管收银页地址，把客户导向 `payment_url` 即可 |
| `converted_currency` / `converted_amount` | 折算为 USD 后的金额（含汇损） |
| `exchange_rate` | 下单时刻锁定的实际结算汇率（含汇损） |
| `surcharge_percent` / `surcharge_fee` | 汇损百分比 / 汇损费用（USD） |
| `payment_method` | **系统在下单时就已锁定的唯一支付方式**，不会返回候选列表——如果 `group_key` 下所有支付方式都被风控拦截，本次请求会直接失败（见下方错误码 `NO_AVAILABLE_PAYMENT_METHOD`），不会创建订单。若请求里传了 `payment_method_key`，这里返回的就是指定的那个渠道 |
| `status` | 订单状态，创建成功后固定为 `pending` |

### 失败

```json
{
  "code": 422,
  "msg": "Amount mismatch: expected 190.00, got 999.99",
  "error_code": "AMOUNT_MISMATCH"
}
```

| HTTP 状态码 | `error_code` | 触发条件 |
|---|---|---|
| 401 | — | `App-ID`/`Timestamp`/`X-Nonce` 缺失、时间戳过期（>5分钟）、Nonce 重复使用、App-ID 不存在或已禁用、签名校验失败 |
| 422 | `Validation failed`（无 `error_code`，走标准校验错误格式，`errors` 字段带具体字段错误） | 请求体字段格式不对（必填缺失、类型不对、超长等） |
| 422 | `AMOUNT_MISMATCH` | `amount` 与 `subtotal+shipping_fee-discount+tax` 的差超过 0.01 |
| 422 | `ITEMS_SUBTOTAL_MISMATCH` | `subtotal` 与所有 `items[].unit_price × quantity` 之和对不上 |
| 422 | `CALLBACK_DOMAIN_NOT_ALLOWED` | `notify_url`/`return_url`/`cancel_url` 的域名不在商户的回调域名白名单内 |
| 422 | `PAYMENT_METHOD_NOT_AVAILABLE` | 指定的 `payment_method_key` 在该商户名下不存在，或对应的支付方式已停用（不会创建订单） |
| 422 | `PAYMENT_METHOD_DOMAIN_MISMATCH` | 指定 `payment_method_key` 时，`notify_url`/`return_url`/`cancel_url` 缺失，或其域名与该渠道绑定的电商网站域名不一致（不会创建订单） |
| 409 | `NO_AVAILABLE_PAYMENT_METHOD` | `group_key` 下所有支付方式都被风控阈值拦截，或该支付组不存在/未启用（仅在**未**指定 `payment_method_key` 时出现） |

---

## 幂等性

用 `merchant_order_no` 做幂等键（与商户账号组合唯一）。同一个 `merchant_order_no` 重复提交：
- 如果对应订单**已存在**，直接原样返回该订单的信息（`data` 里的内容和首次创建时一致），**不会**重复创建、也**不会**重新计算汇率或重新走风控。
- 建议商户端网络超时重试时，使用**同一个** `merchant_order_no` 重新发起请求，而不是生成新的订单号——这正是幂等设计的意义所在，可以放心重试不用担心重复扣款/重复发货。

---

## 常见对接问题

1. **金额字段一律传字符串**，不要传数字字面量（`"190.00"` 而不是 `190.00`），避免序列化差异导致签名或金额校验出错。
2. **`sign` 计算时的规范化算法必须和服务端逐字节一致**——尤其是"关联数组按 key 排序、列表保持原序"这条规则，这是最容易出问题的地方。
3. **`notify_url`/`return_url`/`cancel_url` 的域名要提前加到商户的回调域名白名单**，不然请求会直接被拒（`CALLBACK_DOMAIN_NOT_ALLOWED`），这个白名单在系统后台"商户管理"里配置（需要超级管理员操作）。
4. **`payment_method` 是系统直接锁定返回的，不是候选列表**——商户端不需要（也没有）二次选择支付方式的环节，收到响应后直接把客户导向 `payment_url` 完成支付即可。
5. **`payment_method_key` 是可选的"点名渠道"参数**，只在下单站点就是该渠道绑定的电商网站时才用得上：它绕过限额风控，但要求 `notify_url`/`return_url`/`cancel_url` 三个地址全部传齐且域名与渠道绑定域名完全一致，否则会以 `PAYMENT_METHOD_DOMAIN_MISMATCH` 拒单。不确定要不要用就别传，交给系统按支付组自动路由。

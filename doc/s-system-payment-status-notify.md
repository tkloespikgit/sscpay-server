# 支付状态回调通知变更说明（致S系统开发）

本文档面向S系统开发者，说明支付网关聚合插件（sscpay-gateway-aggregator）新增
"支付争议/拒付"处理后，`payment_status` 回调通知的变化，以及新增的主动查询订单接口，
请据此评估S系统需要做的改动。

## 一、通知机制（本次未变更，仅供对齐）

- **触发方式**：插件收到网关（PayPal/Stripe/Airwallex/Antom）webhook、订单状态发生
  **真实迁移**（幂等重复事件不算）后，异步投递一条通知到下单时S系统传入的 `callback_url`。
- **请求方式**：`POST`，`Content-Type: application/json`，body为原始JSON字符串。
- **签名验证**：请求头 `X-PGA-Signature`，值为 `HMAC-SHA256(shared_secret, 原始JSON请求体字节)`
  的十六进制编码。共享密钥需提前和插件方约定一致（插件后台设置页配置）。S系统侧建议用
  **常量时间比较**（如PHP的`hash_equals()`）校验签名，不要用`==`直接比较。
- **重试策略**：S系统需返回HTTP 2xx表示已接收，非2xx或超时会按 `1分钟→5分钟→30分钟→2小时→6小时`
  的退避节奏重试，共5次；5次仍失败则不再自动重试，需要人工介入核对。
  **因此同一条业务事件，S系统侧可能收到多次相同payload的投递**（比如S系统第1次处理成功但
  网络原因导致插件没收到2xx、触发了重试），S系统的处理逻辑需要保证幂等（建议以
  `s_order_id + status + updated_at` 或自建的去重键判断是否已处理过）。

## 二、Payload字段（本次未变更）

```json
{
  "event": "payment_status",
  "s_order_id": "S系统订单号",
  "wp_order_id": 12345,
  "payment_method": "paypal_rest | paypal_js | stripe | airwallex | antom",
  "status": "见下方状态清单",
  "transaction_id": "三方交易ID，可能为null",
  "amount": "10.00",
  "currency": "USD",
  "paid_at": "2026-09-02 10:00:00 或 null（UTC时间）",
  "updated_at": "2026-09-02 10:00:00（UTC时间）"
}
```

## 三、订单状态清单（本次新增两项，见标 ⭐）

| status值 | 含义 | 是否终态 |
|---|---|---|
| `pending` | 待支付 | 否 |
| `paid` | 已支付 | 否（可能转 `disputing`/`refunded`） |
| `failed` | 支付失败 | 是 |
| `cancelled` | 已取消 | 是 |
| `expired` | 已过期 | 是（**不会**通知，见第五节） |
| `refunded` | 商家主动退款/网关退款 | 是 |
| ⭐ `disputing` | **争议中**：收到买家发起的争议/客诉（如PayPal Dispute、Stripe Chargeback Inquiry），结果未定 | 否 |
| ⭐ `confused` | **拒付**：争议最终判定商家败诉，或网关直接反转扣款（chargeback成立），资金已被强制扣回 | 是 |

### 状态流转（与本次通知相关的部分）

```
paid --[收到争议]--> disputing --[争议胜诉]--> paid
                          |
                          +---[争议败诉/资金被强制反转]--> confused（终态）
                          |
                          +---[争议过程中被退款了结]------> refunded（终态）

paid --[网关未走完整争议流程、直接反转扣款]--> confused（终态）
```

## 四、新增场景的payload示例

**收到争议（`disputing`）**——此时资金仍在商家账户，但强烈建议S系统收到后**暂停发货/暂停后续履约动作**，待结果明确：

```json
{
  "event": "payment_status",
  "s_order_id": "S20260901001",
  "wp_order_id": 12345,
  "payment_method": "paypal_rest",
  "status": "disputing",
  "transaction_id": "8XY123456A",
  "amount": "199.00",
  "currency": "USD",
  "paid_at": "2026-08-20 03:00:00",
  "updated_at": "2026-09-02 09:00:00"
}
```

**争议败诉/拒付（`confused`）**——资金已被扣回，等价于"被动退款"，建议S系统按退款/资损处理，
并触发对应的客服/财务工单（比如判断订单是否已发货，是否需要联系客户或走异常留证流程）：

```json
{
  "event": "payment_status",
  "s_order_id": "S20260901001",
  "wp_order_id": 12345,
  "payment_method": "paypal_rest",
  "status": "confused",
  "transaction_id": "8XY123456A",
  "amount": "199.00",
  "currency": "USD",
  "paid_at": "2026-08-20 03:00:00",
  "updated_at": "2026-09-03 02:00:00"
}
```

**争议胜诉**——直接收到 `status: "paid"` 的通知（与最初支付成功的通知格式完全一样，
S系统侧靠 `updated_at` 变化和当前订单已经是 `disputing` 这个事实来判断"这是一次争议胜诉后的回退"，
payload本身不会额外标注"这是胜诉"）。

## 五、S系统需要确认/修改的点

1. **状态枚举扩容**：订单状态字段/枚举类型需要能接收并存储 `disputing`、`confused` 这两个新值，
   不要因为"未知状态"而报错或丢弃这条通知。
2. **`disputing` 的业务动作**：建议至少做到——暂停发货/物流环节、在客服后台标红提醒人工跟进。
   具体动作由S系统业务决定，插件侧只负责通知"发生了什么"。
3. **`confused` 的业务动作**：建议等价于"该笔订单的钱没有了"，走你们现有的退款/资损处理流程
   （区别于商家主动发起的 `refunded`，`confused` 是网关强制扣回，可能需要额外的对账/黑名单/取证动作）。
4. **幂等处理**：如上，同一事件可能因为重试收到多次相同payload，S系统处理逻辑需要能重复接收同一条
   通知而不产生副作用重复（比如不要重复扣库存回滚、不要重复发客服工单）。
5. **`paid` 状态的二义性**：收到 `status: "paid"` 时，如果S系统记录里这笔订单**当前正处于
   `disputing`**，说明这是"争议胜诉后回退"，不是首次支付成功，请不要重复走首次支付成功的业务流程
   （比如不要重复触发发货）。

## 六、已知限制（本次未覆盖，暂不需要S系统改动，仅告知）

- **`expired`（订单过期）不会触发本通知**：这条状态迁移发生在插件内部的惰性检查（买家访问支付页时
  才检测），走的是完全不同的代码路径，没有接入通知队列。如果S系统依赖订单是否过期来做业务判断，
  目前只能靠自己记录的支付超时时间（下单时的 `expires_at`）自行判断，不要等插件通知。
- **Stripe的争议事件**（`charge.dispute.*`）已按官方文档字段实现，可信度较高。
- **Airwallex、Antom的争议事件名称和字段**是插件方按同厂商其它webhook的命名习惯推断实现的，
  **尚未用两家的真实沙箱通知样例核实过**，存在收不到通知或解析失败的风险。这两个网关的
  `disputing`/`confused` 通知何时能稳定触发，取决于插件方后续核实结果，会另行同步，
  S系统目前按"这两个网关也可能收到这两个新状态"来做兼容即可，不必因为暂时收不到而认为有问题。

## 七、新增：主动查询订单最新状态接口

除了被动等待 `payment_status` 回调，现在也可以**主动查询**某笔订单当前的最新状态——用来对账、
补偿"回调5次重试都失败了"的极端情况，或者查询本身不会触发通知的 `expired` 状态（见第六节）。

- **Endpoint**：`POST {插件站点}/wp-json/payment-plugin/v1/order-query`
- **鉴权**：和 `/v1/pay`、`/v1/sync-tracking` 一样，二选一：
  1. WooCommerce REST API Key（`consumer_key`/`consumer_secret`，S系统大概率已经在用这套）；
  2. WordPress应用密码 + Basic Auth。
  不需要 `X-PGA-Signature`（那个签名只用于插件→S系统方向的回调，这里是S系统→插件方向，走标准REST鉴权）。
- **请求体**：

  ```json
  { "s_order_id": "S20260901001" }
  ```

- **成功响应**（`code: 0`）：

  ```json
  {
    "code": 0,
    "message": "查询成功",
    "data": {
      "s_order_id": "S20260901001",
      "wp_order_id": 12345,
      "payment_method": "paypal_rest",
      "status": "disputing",
      "transaction_id": "8XY123456A",
      "currency": "USD",
      "amount": "199.0000",
      "pay_url": "https://site/payment/S20260901001?token=...",
      "tracking": {
        "tracking_number": null,
        "shipping_company": null,
        "tracking_url": null,
        "ship_date": null
      },
      "expires_at": "2026-08-20T03:30:00Z",
      "paid_at": "2026-08-20T03:00:00Z",
      "created_at": "2026-08-20T02:55:00Z",
      "updated_at": "2026-09-03T09:00:00Z"
    }
  }
  ```

  `status` 字段的取值就是第三节那张状态清单，包括新增的 `disputing`/`confused`；`paid_at`/
  `ship_date` 未发生时为 `null`。

- **错误响应**：
  - 订单不存在：`{ "code": 10001, "message": "订单不存在" }`
  - 参数缺失/格式不对（如 `s_order_id` 带了非法字符）：`code: 10005`

- **和回调通知的关系**：字段含义与 `payment_status` 通知一致（同一份`status`枚举），可以把这个接口
  当作"回调通知的补充查询手段"，不是替代——正常情况下还是应该以回调为主，这个接口用于兜底核对，
  不建议做成高频轮询（比如每秒查一次），会给插件站点数据库增加不必要的压力。

## 八、新增：查询订单日志接口

用来排查一笔订单在插件侧完整的支付链路记录（下单、发起支付、Webhook回调、争议事件、重试等），
本次新增的争议/拒付ERROR日志（见第五节）也是通过这个接口查看的。

- **Endpoint**：`POST {插件站点}/wp-json/payment-plugin/v1/order-logs`
- **鉴权**：同第七节——WooCommerce REST API Key 或 WordPress应用密码，二选一，不需要签名头。
- **请求体**：

  ```json
  { "s_order_id": "S20260901001" }
  ```

- **成功响应**（`code: 0`）：查不到日志不算错误（可能是订单号写错，也可能是订单刚创建还没产生日志），
  统一返回空列表 + `total: 0`：

  ```json
  {
    "code": 0,
    "message": "查询完成",
    "data": {
      "s_order_id": "S20260901001",
      "total": 2,
      "logs": [
        {
          "id": 981,
          "level": "ERROR",
          "message": "支付争议已解决：商家败诉，资金将被拒付扣回；网关：paypal_rest；S系统订单号：S20260901001；争议单号：PP-D-123；三方交易ID：8XY123456A；涉及金额：USD 199；原因：MERCHANDISE_OR_SERVICE_NOT_RECEIVED",
          "payment_method": "paypal_rest",
          "wp_order_id": 12345,
          "request_data": null,
          "response_data": null,
          "callback_data": { "...": "webhook事件解析后的原始结构，字段见第四节示例" },
          "ip": "203.0.113.10",
          "user_agent": "PayPal/...",
          "created_at": "2026-09-03 02:00:00"
        }
      ]
    }
  }
  ```

  - `level`：`INFO` / `WARNING` / `ERROR` 三档，本次新增的争议/拒付类日志固定是 `ERROR`
    （详见第五节，是有意做成醒目级别，方便S系统或人工在日志列表里一眼筛出来）。
  - `message`：人工可读的中文描述，插件侧已经拼装好，不需要S系统再解析拼接。
  - `request_data`/`response_data`/`callback_data`：落库前已经过敏感信息脱敏，能解析成JSON的
    返回嵌套对象，解析不了的历史脏数据原样返回字符串；没有对应数据时为 `null`。
  - `created_at`：`Y-m-d H:i:s` 格式的**UTC时间**（注意这里和 `/order-query`、回调通知不同，
    **不是** `Y-m-d\TH:i:s\Z` 这种ISO8601格式，是插件后台LogViewer同款的朴素格式，S系统解析时
    按UTC时区处理即可）。
  - 单次最多返回500条，按 `created_at` 倒序（最新的在前）。

- **错误响应**：只有参数缺失/格式不对会报错（`code: 10005`），订单号查无此单也返回成功、
  空列表，不返回 `10001`——这点和 `/order-query` 不一样，调用时注意区分。

- **和第五节争议日志的配合**：收到 `disputing`/`confused` 回调通知后，如果S系统想看到更完整的
  上下文（比如争议原因、涉及的三方交易ID、原始webhook payload），可以用这个接口按 `s_order_id`
  查一遍，`callback_data` 字段里就是插件解析出的完整事件详情。

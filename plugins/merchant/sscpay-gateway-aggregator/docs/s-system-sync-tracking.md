# 物流同步接口改造说明（致S系统开发）

本文档面向S系统开发者，说明 `/v1/sync-tracking` 接口的改造内容，请据此更新S系统这边的
调用代码。**这是一次不兼容的参数变更**（旧参数名 `shipping_company` 不再被接受），
上线前请S系统同步改造，不要在插件侧已升级、S系统侧还在传旧参数的情况下上线。

## 一、本次变更概述

| 项目 | 变更前 | 变更后 |
|---|---|---|
| 承运商字段 | `shipping_company`（自由文本，公司名） | `carrier_code` + `is_other_carrier`（Y/N），见第三节字段说明 |
| 发货时间字段 | `ship_date` | `shipped_at`（字段改名，含义不变） |
| 是否同步到支付渠道方 | 不支持，插件只落库 | 新增 `need_sync_to_remote`（Y/N）参数，`Y` 时插件会调用支付渠道方（目前仅PayPal）的物流同步API |
| 响应体 | 无同步渠道方相关字段 | 新增 `synced_to_remote`（bool）、`remote_sync_message`（string） |

本地落库（`wp_payment_transactions` 表 + WooCommerce订单）这部分行为不变，**始终执行**，
不受 `need_sync_to_remote` 影响；变化的只是"要不要额外调用一次支付渠道方的API"。

## 二、Endpoint / 鉴权

- **Endpoint**：`POST {插件站点}/wp-json/payment-plugin/v1/sync-tracking`
- **鉴权**：与 `/v1/pay` 一致，二选一：
  1. WooCommerce REST API Key（`consumer_key`/`consumer_secret`）；
  2. WordPress应用密码 + Basic Auth。
- **Content-Type**：`application/json`

## 三、请求参数

```json
{
  "s_order_id": "S20260901001",
  "tracking_number": "1Z999AA10123456784",
  "carrier_code": "UPS",
  "is_other_carrier": "N",
  "tracking_url": "https://www.ups.com/track?tracknum=1Z999AA10123456784",
  "shipped_at": "2026-09-04 10:00:00",
  "need_sync_to_remote": "Y"
}
```

| 字段 | 必填 | 类型 | 说明 |
|---|---|---|---|
| `s_order_id` | 是 | string | S系统订单号，需能查到一笔已存在的交易 |
| `tracking_number` | 是 | string | 物流单号。同一个单号不能被**其它订单**占用（同一订单重复提交视为更新，允许） |
| `carrier_code` | 是 | string | 承运商代码，见下方"承运商字段说明" |
| `is_other_carrier` | 是 | `Y` / `N` | `N`＝`carrier_code`是渠道方认识的标准承运商代码；`Y`＝渠道方枚举里没有对应项，`carrier_code`当自由文本的承运商名称处理 |
| `tracking_url` | 否 | string | 物流查询链接，传了必须是合法URL，否则报错 |
| `shipped_at` | 否 | string | 发货时间，任意`strtotime()`能解析的格式（推荐`YYYY-MM-DD HH:mm:ss`） |
| `need_sync_to_remote` | 是 | `Y` / `N` | `Y`＝本地落库后再调用支付渠道方的物流同步API；`N`＝只落库，不调用渠道方 |

**校验规则**：

- 缺任意一个必填字段 → `code: 10005`，`message` 里会列出具体缺了哪个字段。
- `is_other_carrier` / `need_sync_to_remote` 只接受严格的大写 `Y`/`N`，传其它值（如小写`y`、`true`、`1`）会被判定为参数错误，返回 `10005`。
- `tracking_url` 传了但不是合法URL → `10005`。

### 承运商字段说明（`carrier_code` / `is_other_carrier`）

这两个字段是本次改造新增的、专门为"把物流信息同步给支付渠道方"服务的（PayPal的
Add Tracking Information API要求承运商用它自己的枚举值表达，不认识自由文本公司名）：

- `is_other_carrier = "N"`：`carrier_code` 必须是**支付渠道方认识的承运商代码**。
  以PayPal为例，需要传PayPal官方"Supported Carriers"列表里的代码（如 `UPS`、`FEDEX`、
  `USPS`、`DHL` 等，插件会自动转大写后原样传给PayPal，不做校验/映射）。
  **S系统这边需要维护一份自己的承运商 → PayPal承运商代码的映射表**，不能直接把
  自己系统里的承运商公司名传过来。
- `is_other_carrier = "Y"`：PayPal的承运商枚举里没有对应项，`carrier_code` 直接传
  自由文本的承运商名称（插件会以 `carrier: "OTHER", carrier_name_other: carrier_code`
  的形式传给PayPal）。
- 如果这笔订单的支付渠道是Stripe/Airwallex/Antom（本身不支持同步物流到渠道方，见第五节），
  `carrier_code`/`is_other_carrier` 仍然会正常落库（供后台查看用），只是不会被拿去调用
  任何三方API，两个字段传什么值都不影响本地落库结果。

## 四、处理逻辑

1. 校验参数、校验订单存在且状态为 `paid`（非 `paid` 订单不允许同步物流，返回 `10002`）、
   校验物流单号未被其它订单占用（返回 `10003`）。
2. **本地落库**（始终执行）：写入 `wp_payment_transactions` 表对应字段，并同步写入
   WooCommerce订单的meta（`_tracking_number`/`_carrier_code`/`_is_other_carrier`/
   `_tracking_url`/`_ship_date`）与一条订单备注。这一步成功之后接口的核心目的已经达成。
3. **按 `need_sync_to_remote` 决定是否调用渠道方API**：
   - `need_sync_to_remote = "N"`：跳过，直接视为同步成功。
   - `need_sync_to_remote = "Y"`：调用该笔订单对应支付渠道（`payment_method`）的物流同步
     能力，结果（成功/失败/该渠道不支持）通过响应体的 `synced_to_remote`/
     `remote_sync_message` 如实告知，**不影响本次请求本身的成功与否**——只要第2步本地
     落库成功，接口就返回 `code: 0`，S系统需要额外检查 `synced_to_remote` 字段来判断
     渠道方那边是否真的同步成功。

### 各支付渠道对"同步物流到渠道方"的支持情况

| `payment_method` | 是否支持 | 说明 |
|---|---|---|
| `paypal_js` / `paypal_rest` | ✅ 支持 | 调用PayPal Orders v2的Add Tracking Information API。**⚠️ 该实现尚未用PayPal真实沙箱订单核实过字段名和响应结构，上线前建议先用测试订单验证一遍**（承运商枚举值列表也需要以PayPal最新文档为准） |
| `stripe` | ❌ 不支持 | Stripe标准支付流程没有对应的物流回传API |
| `airwallex` | ❌ 不支持 | 未找到对应API |
| `antom` | ❌ 不支持 | 未找到对应API |

不支持的渠道，`need_sync_to_remote = "Y"` 时会直接返回 `synced_to_remote: false`，
`remote_sync_message` 里会写明"XX不提供物流信息同步接口"，**这不代表接口调用失败**，
只是如实反映"这个渠道没有这个能力"。

PayPal侧还有一种会失败的情况：这笔订单在插件里还没有拿到PayPal的capture id（正常流程下
`status=paid`的订单一定有，只有极老的历史脏数据可能没有），此时 `synced_to_remote: false`，
`remote_sync_message` 为"缺少PayPal订单号或capture id，暂时无法同步物流信息"。

## 五、成功响应

HTTP 200，`code: 0`：

```json
{
  "code": 0,
  "message": "物流单号同步成功",
  "data": {
    "s_order_id": "S20260901001",
    "wp_order_id": 12345,
    "tracking_number": "1Z999AA10123456784",
    "synced_to_remote": true,
    "remote_sync_message": "PayPal物流信息同步成功",
    "updated_at": "2026-09-04T10:05:00Z"
  }
}
```

- `synced_to_remote`：
  - `need_sync_to_remote = "N"` 时恒为 `true`，`remote_sync_message` 固定为
    "未要求同步至渠道方（need_sync_to_remote=N）"；
  - `need_sync_to_remote = "Y"` 时是渠道方API的真实调用结果（见上表）。
- `remote_sync_message`：人工可读的中文说明，成功/不支持/失败都会给出具体原因，
  不需要S系统再解析拼接。
- `updated_at`：本次更新时间，`Y-m-d\TH:i:s\Z` 格式的UTC时间。

**`need_sync_to_remote = "Y"` 但渠道方同步失败**的响应示例（HTTP依然是200，`code`依然是0，
因为本地落库已经成功）：

```json
{
  "code": 0,
  "message": "物流单号同步成功",
  "data": {
    "s_order_id": "S20260901001",
    "wp_order_id": 12345,
    "tracking_number": "1Z999AA10123456784",
    "synced_to_remote": false,
    "remote_sync_message": "PayPal同步物流信息失败：{\"name\":\"...\",\"message\":\"...\"}",
    "updated_at": "2026-09-04T10:05:00Z"
  }
}
```

**⚠️ 重要**：判断"渠道方是否同步成功"请一律看 `data.synced_to_remote`，不要看外层
`code`——外层 `code: 0` 只代表"本地落库成功"，这是本接口设计上的既定行为（见第四节第3步），
不是bug。

## 六、错误响应

| `code` | 触发条件 | HTTP状态码 |
|---|---|---|
| `10005` | 缺少必填参数 / 参数格式不对（`is_other_carrier`、`need_sync_to_remote`不是`Y`/`N`、`tracking_url`不是合法URL等） | 400 |
| `10001` | `s_order_id` 查无对应订单；或订单存在但对应的WordPress订单已被删除 | 400 |
| `10002` | 订单当前状态不是 `paid`（比如还在`pending`，或已经`cancelled`/`refunded`等），`message`里会带上当前实际状态 | 400 |
| `10003` | `tracking_number` 已被**其它**订单占用（同一订单重复提交同一个/不同的单号都算更新，不会触发这个错误） | 400 |

## 七、幂等性

本接口**允许重复调用**（不是一次性接口）：同一个 `s_order_id` 多次调用会直接覆盖更新
`tracking_number`/`carrier_code`/`is_other_carrier`/`tracking_url`/`shipped_at`，
也会再触发一次（如果 `need_sync_to_remote="Y"`）渠道方同步调用和一条新的WooCommerce订单
备注。如果只是想更新物流单号但不想再次触发渠道方同步，传 `need_sync_to_remote="N"` 即可。

## 八、与 `/v1/order-query` 的联动

`/v1/order-query` 响应里的 `tracking` 块本次同步新增了 `carrier_code`/`is_other_carrier`
两个字段（与本接口的输入字段同名），可以用来回读刚才同步的物流信息：

```json
"tracking": {
  "tracking_number": "1Z999AA10123456784",
  "carrier_code": "UPS",
  "is_other_carrier": "N",
  "shipping_company": "UPS",
  "tracking_url": "https://www.ups.com/track?tracknum=1Z999AA10123456784",
  "ship_date": "2026-09-04T10:00:00Z"
}
```

- `shipping_company` 是改造前的历史字段名，**继续保留**且会同步写入 `carrier_code` 的值，
  兼容还在读这个老字段的S系统逻辑；新对接建议直接读 `carrier_code`。
- 时间字段这里仍然叫 `ship_date`（本接口`/v1/sync-tracking`的输入参数虽然改名叫了
  `shipped_at`，但 `/v1/order-query` 的输出字段名保持不变，避免影响已经在读这个字段的
  S系统逻辑）。

## 九、升级检查清单

1. S系统调用 `/v1/sync-tracking` 时**必须**把 `shipping_company` 换成
   `carrier_code` + `is_other_carrier`，并补上 `need_sync_to_remote` 字段——三者都是必填，
   缺了会直接收到 `10005`。
2. 如果S系统希望PayPal订单的物流信息真正同步到PayPal（买家能在PayPal里看到物流状态），
   需要维护一份"承运商 → PayPal承运商代码"的映射表，映射不到的用
   `is_other_carrier="Y"` + 自由文本兜底。
3. 如果S系统之前依赖 `shipping_company` 传的是"公司全称"这类展示文本，注意
   `carrier_code`/`is_other_carrier="N"` 场景下这个字段现在会被要求是一个**代码**
   （用于渠道同步），不再是任意文本——展示用的文本建议S系统自己维护，不要依赖这个字段的值做展示。
4. 判断渠道方是否真的同步成功，请读响应体的 `data.synced_to_remote`，不要只看外层 `code`。

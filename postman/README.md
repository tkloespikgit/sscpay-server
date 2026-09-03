# Postman 测试模板使用说明

## 导入

1. Postman → Import → 把 `Order-System-API.postman_collection.json` 和
   `Order-System-Local.postman_environment.json` 都拖进去。
2. 右上角环境切换器选中 "Order System - Local"。
3. 点环境右边的眼睛图标，把 `base_url` / `app_id` / `api_key` / `group_key`
   改成你实际的值：
   - `app_id` / `api_key`：系统后台「应用管理」里新建一个 Application 后自动生成
   - `group_key`：系统后台「支付组」里配置的组标识
   - `base_url`：你本地跑起来的地址，Docker 方案默认是 `http://localhost:8080`

## 签名逻辑写在哪

**Collection 级别的 Pre-request Script**（不是每个请求单独配置）——点 Collection
名字 → Scripts 标签页 → **Pre-request 子标签**能看到（Postman 新版 UI 把
Scripts 拆成了 Pre-request / Tests 两个子标签，默认停在 Tests，需要手动切一下；
老版本 Postman 是独立的 "Pre-request Script" 标签）。对这个 Collection 下所有
请求自动生效，新增请求不需要重复配置签名逻辑。

## 包含的请求

| 请求 | 验证什么 |
|---|---|
| 创建订单（happy path） | 正常下单，金额公式对得上，预期 200 + code=0 |
| 创建订单 - 幂等测试 | `merchant_order_no` 固定不变，连续跑两次，预期两次拿到**完全相同**的 `order_no`（2.2 节幂等性铁律） |
| 创建订单 - 金额不符 | 故意让 `amount` 和 `subtotal+shipping_fee-discount+tax` 对不上，预期 422 + `error_code: AMOUNT_MISMATCH`（2.1 节金额公式铁律） |
| 查询订单 - 按 order_no | 依赖"创建订单（happy path）"跑完后自动存的 collection 变量 `last_order_no` |
| 查询订单 - 按 merchant_order_no | 配合"幂等测试"里那个固定的 `merchant_order_no` 使用 |

## 关于 `merchant_order_no` 写 "AUTO" 的技巧

"创建订单（happy path）"请求体里 `merchant_order_no` 写的是字面量字符串
`"AUTO"`，Pre-request Script 检测到这个值会自动换成 `TEST` + 当前时间戳，
这样每次点 Send 都是一笔新订单，不会因为幂等机制反复拿到同一条旧数据。
如果你就是想测幂等性，把它改成一个固定字符串（"幂等测试"那个请求就是这么做的）。

## ⚠️ 一个容易踩的坑：不要在请求体里直接写 `{{变量}}`

签名是对**请求体的字节内容**算的。Postman 对请求体文本里 `{{变量}}` 占位符
的替换，发生在 Pre-request Script 跑完**之后**、真正发送请求**之前**。如果
签名时用的是还没替换的占位符原文，跟实际发出去的字节就对不上，服务端验签
100% 失败，而且报错只会是笼统的"签名验证失败"，不会告诉你是这个原因。

所以这份模板里，凡是参与签名的字段（`group_key`、`merchant_order_no`）都是
在脚本里直接用 JS 读 Environment 变量或生成动态值、直接改 JS 对象，不依赖
Postman 自己的 `{{}}` 文本替换。如果你要在请求体里加新的动态字段，也应该照
这个思路改脚本，而不是图省事直接在 JSON 文本里写 `{{xxx}}`。

`base_url`（在 URL 里）和 `last_order_no`（用在 GET 请求的 query 参数里）
这两个可以放心用 `{{}}`，因为它们不参与签名内容，Postman 什么时候替换都无所谓。

## 手动测试 401（签名/凭证错误）

这份模板没有专门做一个"故意签名错误"的请求（因为脚本总是算出正确签名），
最简单的测法：把 Environment 里的 `api_key` 临时改成随便一个错误值，重新跑
"创建订单（happy path）"，应该收到 401 + "Signature verification failed"。
测完记得把 `api_key` 改回正确值。

## GET 请求为什么也带了个 body `{}`

`/order/query` 走的是和 `/order/create` 同一套 `api.auth` 中间件
（`App\Http\Middleware\ApiAuthentication`），这个中间件不管 HTTP 方法是什么，
一律要求请求体里有 `sign` 字段。所以即便是 GET 请求，也需要带一个 JSON body
（哪怕内容是空对象 `{}`）用来承载签名——Postman 对 GET 请求发 body 没有限制，
只是界面上会有个不影响使用的小提示。真正用来查询的 `order_no`/
`merchant_order_no` 走的是 URL 上的 query string，不受 body 内容影响。

# SSCPay Gateway Aggregator

对应设计文档 v2 的完整实现。这份 README 是给接手部署/继续开发的人看的操作说明，
不是发给最终站点的（发布zip时会被 `.distignore` 排除）。

## 安装前必做

1. `composer install --no-dev`（生产环境）或 `composer install`（本地开发，会额外装测试工具，如果以后加了的话）。
2. 收款站首次使用平台默认回调密钥时，先激活一次平台私下提供的初始化插件。旧站须在旧版网关仍启用时先完成这一步，避免历史网关凭证无法解密。装好公开主包后停用并删除初始化插件。详见[升级说明](UPGRADE.md#二在线更新怎么配置)。主插件默认从 `www.ssecurepay.com` 检查更新，无需令牌。
3. 服务器crontab里加一行（每分钟触发一次真实cron，和 DISABLE_WP_CRON 配套）：

```
* * * * * curl -s https://your-site.com/wp-cron.php >/dev/null 2>&1
```

4. 确保WooCommerce已安装并启用（Requires WC >= 5.0）。
5. 启用后，在「支付网关聚合 → 设置」确认回调密钥已配置。也可展开「修改回调密钥」保存新值；页面及 HTML 均不包含现有密钥。S 系统使用同一密钥验证插件回调。
6. API认证：插件接口同时支持两种认证，任选其一：
   - （推荐）直接复用 WooCommerce REST API 密钥：S系统已保存过WC密钥的话，
     请求时在URL参数里带 `consumer_key`/`consumer_secret` 即可，不需要单独建账号；
     注意密钥必须“读写”权限才能调 `/v1/gateway-config`，且只允许HTTPS。
   - 或分别给「支付插件-订单接口账号」和「支付插件-网关配置账号」创建 WordPress 应用密码，走 HTTPS Basic Auth。

## 逐笔退款（1.16.0）

Stripe、PayPal、Airwallex 和 Antom 的成功退款按渠道退款编号和实际原币金额回传 S 系统。同一支付可以有多笔部分退款。确认金额累计达到订单金额后，付款站才把订单标记为已退款。出站队列在收到对应退款号的确认后结束投递。查单附带 `include_refunds: true` 可读取、补查同一组凭证。协议和升级顺序见[退款通知协议](docs/s-system-payment-status-notify.md#逐笔退款通知)及[升级说明](UPGRADE.md#1160-逐笔退款通知)。

本地执行 `php tests/refund-delivery.php` 和 `php tests/callback-queue-recovery.php` 可验证部分退款、重复推送、查询补单、投递确认与断点恢复。

## 商品与变体同步（1.15.0）

`GET /wp-json/wc/v3/products-with-variations` 返回商品和内联变体；参数为 `page`、`per_page`（默认 20，最多 100）及可选 `force_refresh=true`。分页头沿用 `X-WP-Total` 和 `X-WP-TotalPages`；`GET /wp-json/wc/v3/ninox-status` 保留旧兼容信息。两条路由都需要有读权限且属于具备 `manage_woocommerce` 的用户的 WooCommerce REST 密钥，只走 HTTPS（显式本地开发模式除外）。兼容原 query 凭证、Basic Auth 和原明文 Authorization 格式。

从独立 `sscpay-products-api` 升级时，先更新聚合插件，再停用旧插件。用原地址和密钥确认两个接口正常后，即可删掉独立插件。没有额外数据库迁移。

后台保存值优先于私有初始化包预置的默认值，公开更新不会覆盖两者。原先显式配置过站点常量的安装保留兼容。轮换时须同步更新 Laravel 的 `PGA_WEBHOOK_SECRET` 和使用该密钥的收款站。

## /v1/pay 客户 IP（1.14.0）

可在请求 JSON 顶层传 `customer_ip`，值为单个 IPv4 或 IPv6。收款站将其写入新 WooCommerce 订单的原生客户 IP 字段，后台订单详情可查看。旧版 Laravel 不传该字段仍可建单，WooCommerce 不把 API 调用方的地址标记为客户 IP。字段不参与远端单号的幂等比较，重试不改原值，也不发送给外部支付通道。

## 支付通道的出站代理

通过 `/v1/gateway-config` 注册配置时，在 `config.proxy` 中填写代理地址，例如：

```json
{
  "proxy": "socks5://account:password@proxy.example.com:1080"
}
```

适用于 PayPal JS / REST、Stripe、Airwallex、Antom 的服务器端渠道 API 请求。
代理不会改变买家浏览器访问支付页面、支付平台回调本站或本站回调 S 系统的网络路径。

| 协议 | 示例 | 目标域名解析位置 |
|------|------|------------------|
| HTTP（保留原支持） | `http://account:password@proxy.example.com:8080` | 代理端（CONNECT） |
| SOCKS5 | `socks5://account:password@proxy.example.com:1080` | WordPress 服务器 |
| SOCKS5 代理端解析域名 | `socks5h://account:password@proxy.example.com:1080` | 代理端 |

不需要认证时可省略 `account:password@`。账号、密码中含 `@`、`#`、`%` 等特殊字符时，
先分别进行 URL 编码，例如密码 `p@ss#word` 写成 `p%40ss%23word`。
端口必须是 1–65535 的数字；主机填写 WordPress 服务器可访问的代理 IP 或域名，
不要填写服务监听地址 `0.0.0.0`。需要由代理解析目标域名时使用 `socks5h://`。

省略 `proxy` 或传空字符串时沿用原有未配置代理的请求行为。显式配置代理后，
不受服务器 `NO_PROXY` 环境变量的绕过规则影响；地址非法、不支持的协议或代理连接失败会报错，
不会自动回落为直连。原有 HTTP 配置无需修改；历史订单使用创建时保存的配置快照，
更新网关代理配置不会改写历史订单快照。

协议选项统一由 `src/Helpers/ProxyConfig.php` 生成，普通渠道的 `HttpClient` 与
Stripe SDK 的 `CurlClient` 共用，避免两条请求路径行为不一致。
运行 `php tests/proxy-config.php` 验证代理解析、认证、协议切换和本地代理握手
（需要 PHP cURL、pcntl 扩展及绑定本地 TCP 端口的权限，不连接真实支付平台）。

## /v1/pay 交易类型（trans_type）与账单号（1.4.0 新增）

**这是一次破坏性的接口变更**：`/v1/pay` 新增了 4 个【必传】字段，S 系统不传会直接收到
`10005 缺少必填参数`。插件侧和 S 系统侧需要同步上线。

| 字段 | 类型 | 说明 |
|------|------|------|
| `trans_type` | string | `CREATE` / `MATCH` / `VIRTUAL` / `COPY`，大小写不敏感（落库统一转大写） |
| `invoice_number` | string | 账单号，≤127 字符，只允许 `A-Z a-z 0-9 _ - . / #`（不允许空格、引号、中文） |
| `subject` | string | 订单主题，≤255 字符，允许中文，不允许控制字符。所有模式都必传 |
| `subtotal` | number | 订单商品小计，≥0。`VIRTUAL` 模式下作为虚拟商品的单价 |

### trans_type 决定商品明细怎么组装给支付通道

组装逻辑集中在 `src/Core/LineItemBuilder.php`（网关无关，PayPal / Stripe 共用同一份结果）：

- **MATCH**：商品名位置写**商品名**，SKU 写商品 SKU，数量/单价原样 —— 与 1.4.0 之前的行为一致。
- **CREATE**：商品名位置写**商品 SKU**（SKU 缺失时退回商品名），其余同 MATCH。
- **COPY**：逐条读 `items` 并**原样拷贝**，商品名 = 商品名、SKU = 商品 SKU。与 MATCH 的唯一区别是 COPY **不做「商品名为空时退回 SKU」的兜底**，也不像 VIRTUAL 合成单条。
- **VIRTUAL**：**忽略 `items`**（仍要求必传，只是不参与通道组装），固定合成一条：
  商品名 = `subject`、SKU = `invoice_number`、数量 = 1、单价 = `subtotal`。
  （不向通道暴露内部订单号 `s_order_id`；历史交易没有账单号时 SKU 留空。）

### 商品详情页链接

`items[].product_url` 由 S 系统在调 `/v1/pay` 之前连同 `product_id` 一起传过来
（S 系统建商品在先、发起支付在后，所以这个链接必然已经存在），插件**原样透传**，
不按 SKU 回查站内商品库。

| 通道 | SKU 落位 | 商品链接落位 |
|------|----------|--------------|
| PayPal（`paypal_js` / `paypal_rest`） | `purchase_units[].items[].sku` | `purchase_units[].items[].url` |
| Stripe | `line_items[].price_data.product_data.metadata.sku` | 同左的 `.metadata.product_url` |

Stripe 的 `product_data` 没有 SKU/URL 的专属字段位，只能进 `metadata`（值上限 500 字符，
插件会先截断，避免超长导致整个 Session 创建请求被拒）。运费/税费那两条合成行项目不带 metadata。

只做最低限度校验：必须是 `http`/`https` 的绝对地址，否则当没传（链接对各通道都是
可选字段，不值得为一个链接让整笔订单被通道拒掉）。

VIRTUAL 模式合成的那条不对应真实商品，没有详情页可指，用**店铺首页**
（`wc_get_page_permalink('shop')`，取不到退回站点首页）。

⚠️ 这里**不能**放支付结果页或 order-received 页：前者带 `pay_token`（能调公开的
`/v1/paypal-js/capture`），后者带 WooCommerce order key（游客凭它能看整张订单），
而 `items[].url` 是要交给通道、并展示在买家交易详情里的 —— 只能放不含任何密钥的公开地址。

各模式下 `items` 都必须传（`validate()` 里 `items 不能为空` 的校验没有放开），
`OrderManager` 创建 WooCommerce 订单时始终按 `items` 建行项目 —— trans_type 只影响
**发给支付通道的报文**，不影响站内订单长什么样。

### 账单号的落位

所有通道、所有 trans_type 模式下都会带上 `invoice_number`：

| 通道 | 落位字段 |
|------|----------|
| PayPal（`paypal_js` / `paypal_rest`） | `purchase_units[].invoice_id`（会展示在买家交易历史和邮件通知里） |
| Stripe | Checkout Session 的 `metadata.invoice_number` |
| Airwallex | Payment Intent 的 `metadata.invoice_number` |
| Antom | `order.orderDescription` 文本里（Antom 没有 metadata/账单号字段） |

历史交易（1.4.0 之前建的）`invoice_number` 为空，各通道会**跳过不写**这个字段，
不会因为传空字符串被三方拒绝。

### VIRTUAL 模式的金额自洽要求

PayPal Orders v2 会强校验 `item_total + shipping + tax_total - discount === amount.value`。
VIRTUAL 模式只有一条数量为 1 的商品，所以这个校验等价于要求：

```
subtotal + shipping_fee + tax_fee - discount_fee === amount
```

S 系统传的 `subtotal` 不自洽时**不会阻断下单** —— 走 `PayPalRestClient::createOrder()` 里
既有的降级分支：items 和 breakdown 一起放弃、只发总额，并记一条 WARNING 日志。
想确认某笔订单有没有降级，查 `wp_payment_logs` 里 level=WARNING 的记录。

### 幂等

`trans_type` 和 `invoice_number` 也参与幂等比对：同一个 `s_order_id` 换了交易类型或账单号，
会被判为「请求参数与原订单不一致」（10005），不会拿旧会话当幂等结果返回。

## 取消页与重新支付（1.5.0 新增）

新增第 5 条伪路由 `/payment/{s_order_id}/cancel?token=xxx`。买家自己放弃这一次支付时，
不再直接跳回 S 系统，而是统一落回插件的取消页。

### 页面长什么样

订单仍是 `pending` 时：

- 文案：`Your payment was cancelled. Your order is still open — you can try paying again right away.`
- **Retry Payment** —— 高亮蓝色主按钮，指向 loading 页（`pay_url`），重走一遍完整支付流程。
  复用仍有效的 `gateway_session`；Stripe / PayPal 超过本站会话期限时先核实收款，再按下文规则轮换。
- **Return to Store** —— 灰色次要按钮，仍走 `return-to-shop` 中转端点（含访客风控）再跳 S 系统的 `cancel_url`。

订单已不处于 `pending`（`paid` / `expired` / `cancelled` / `failed` / `refunded`）或已跳回过 S 系统时，
不展示 Retry，按结果页的文案 + 灰色返回按钮处理（已跳回过则自动跳 order-received）。

### 各网关怎么进取消页

| 通道 | 取消入口 |
|------|----------|
| `paypal_js` | Smart Buttons 的 `onCancel` → `/v1/paypal-rest/cancel` → 取消页 |
| `paypal_rest` | PayPal `application_context.cancel_url` → `/v1/paypal-rest/cancel` → 取消页 |
| Stripe | Checkout Session 的 `cancel_url` 直接指向取消页 |
| Airwallex | Hosted Payment Page 的 `failUrl` 直接指向取消页 |
| Antom | **未覆盖** —— AMS pay 接口没有取消回跳字段，买家仍停在 Antom / S 系统侧 |

`paypal_js` 的 capture 调用失败也走同一个中转端点，但带 `reason=capture_failed`，
落到同一个取消页让买家重试。

### user canceled 日志

只在取消页渲染时记一次（四个入口最终都汇到这里，不会重复也不会漏记）：

- 买家主动取消 → `INFO`：`买家取消了支付（user canceled），订单保持pending，取消页已展示重新支付入口`
- capture 失败兜底 → `WARNING`：`capture调用失败后进入取消页（非买家主动取消）……`

`response_data` 里带 `reason` / `status` / `payment_intent` / `expires_at`，
后台按 `s_order_id` 查日志即可。

### 为什么取消不再写 `cancelled` 状态（重要）

1.5.0 之前 `cancelHandler` 会把交易写成 `cancelled`。现在**不写了**，取消这次支付后交易保持
`pending`；后续由实际支付结果更新状态，限时链接也可能在到期后变为 `expired`。

原因是状态机里 `cancelled` 是终态（`TransactionStateMachine`：`CANCELLED => []`，没有任何出边）。
如果买家点取消后又回来付成功了，webhook 的 `cancelled -> paid` 迁移会被 `assertTransition`
直接拒绝，结果是**钱扣了但交易永远停在 cancelled，S 系统也永远收不到已支付回调**。
而取消页要给「Retry Payment」，订单就必须保持可支付，所以这里只能停在 `pending`。

三方明确取消支付时，仍由 webhook 取消事件（`WebhookRouter`）更新为 `cancelled`。
本站支付链接超时则由 `TransactionManager::maybeExpire()` 更新为 `expired`，
两者含义不同；链接过期后的到账补正见下文。

### 升级注意

取消页依赖新的 rewrite rule。`maybeFlushRewriteRules()` 已经加了对 `^payment/([^/]+)/cancel/?$`
的检测，在线更新覆盖代码后下一次请求会自动 flush，**不需要重新激活插件**。
本次没有表结构变更，`PGA_DB_VERSION` 仍是 `1.4.0`。

## 本站支付链接有效期

后台“设置”页的支付链接有效期默认为 `0`，表示本站链接不因时间流逝而过期；
设置为正数时，单位为分钟，从订单创建时开始计算。永久链接在数据库中使用
`9999-12-31 23:59:59` 标记，过期检查会显式跳过该值，无需修改表结构。

`/v1/pay` 和 `/v1/order-query` 的 `expires_at` 均保持字符串类型：永久链接返回
`9999-12-31T23:59:59Z`，限时链接返回实际的 UTC 到期时间。S 系统应将该固定值识别为永久，
不将其判为过期，也不因日期解析器不支持该年份而判为已过期。

升级不会覆盖已有站点保存的有效期设置，也不会改写历史订单的到期时间。
已有站点若需永久链接，可在后台将有效期保存为 `0`，使随后创建的新订单使用永久标记。
这只取消本站的链接时间限制，支付平台会话仍可能自行失效，也不会重新开放已付款或其他已关闭订单。

## 平台会话期限与自动轮换（1.13.0）

`/v1/pay` 仍在创建订单时发起平台会话，成功后才返回付款链接。相同请求再次调用时返回
已有结果，不查询或轮换完整会话。打开付款链接的 loading / checkout 页面时，Stripe、
PayPal JS、PayPal REST 先使用当前 `payment_intent` 查询平台，再决定是否继续支付：

| 通道 | 本站记录的会话轮换期限 |
|------|-----------------------|
| PayPal JS / REST | 每次平台会话创建开始后 2 小时 |
| Stripe | 每次平台 Checkout Session 创建后 23 小时 |

到期时间保存于交易表 `gateway_session_expires_at`（UTC），缓存中同时保留 Unix 秒值
`local_expires_at`。`/v1/pay`、`/v1/order-query` 新增同名 `gateway_session_expires_at` 响应字段，
格式为 ISO 8601 UTC 字符串；尚未创建或不支持轮换的通道可为 `null`。
它与 `expires_at` 不同：`expires_at` 仍表示本站付款链接期限，后台默认 `0` 的永久含义不变。

平台确认到账时，先校验金额/币种，再按正常付款流程更新 WooCommerce、本地状态及交易号，
并通知 S 系统。平台仍未付款且会话达到上述期限，或平台明确确认会话已关闭时，才轮换。
新会话的 2 / 23 小时重新计时，原订单号、付款链接、金额、配置快照和 `created_at` 均保留。
Stripe 平台会话过期不再单独把本地订单写成 `failed`。

这些是本站轮换期限，不是 PayPal 官方到期证明。Stripe 在轮换前须调用平台接口关闭旧
Session 并确认；PayPal 没有同等的未付订单关闭接口，旧页面的 Capture / 回跳因此与轮换
共享订单锁，并且只允许捕获当前 Order ID。旧页面应重新打开本站付款链接。
`APPROVED` / `PAYER_ACTION_REQUIRED` 且没有任何 Capture / Authorization 时，恢复同一个
PayPal Order 供买家继续操作，即使超过本站 2 小时会话期限也不创建第二个 Order。
付款处理中、状态未知、查询错误（包括普通 404 / 鉴权错误）或金额不符时，停止轮换；
仅查询不到资源不能推断为未付款或过期。本地已关闭订单不会因此重新开放。

创建、轮换使用按订单的数据库命名锁，并以 pending 状态及原会话信息作条件更新。
每轮 HTTP 创建前先保存固定幂等键，响应超时后用同键重试；结果不明超过 1 小时则停止
盲目重试，订单日志提示商户核实。历史 Session / Order / Intent / Capture 引用保存在
`payment_session_references`，旧付款通知仍可定位；旧失败通知不会关闭新尝试，第二笔到账
会记录核查提示并保留首笔成功付款身份，不重复履约。

首次创建、历史期限补记、轮换开始/成功及异常均写入对应订单日志，轮换日志包含新旧平台
编号和到期时间。历史订单没有该新字段时，优先从会话元数据推导；缺少会话创建元数据时，
使用原订单 `created_at` 作为兼容估计，不使用会被其他操作改变的 `updated_at`。
升级会自动执行 DB 1.6.0 迁移，新增期限字段和历史引用表，不修改原有迁移或批量重开历史订单。

## PayPal 付款来源被拒绝后的恢复（1.13.7）

Capture 返回 `INSTRUMENT_DECLINED` 表示买家选择的付款来源被拒绝，需重新选择付款方式。
PayPal JS 会解析后端的结构化结果，调用 `actions.restart()` 重新打开 PayPal；PayPal REST
会使用响应中经过 HTTPS 和 PayPal 精确域名校验的继续付款链接。恢复信息保存在现有
`gateway_session` 中，不改变订单付款状态，不需要数据库结构迁移。

买家关闭页面后，仍可重新打开本站原付款链接。每次打开都先查询 PayPal：

- Capture 已完成：核实金额和币种后展示付款结果，不再发起付款。
- 没有 Capture / Authorization、Order 为 `APPROVED` / `PAYER_ACTION_REQUIRED`：
  使用同一个 Order 重新进入 PayPal，让买家继续操作。历史拒付订单即使没有恢复标记也适用，
  前提是原会话缓存完整且与当前 Order ID 一致。
- Order 为 `CREATED`：未到本站会话期限时复用，达到期限时沿用原有轮换规则。
- Order 明确 `VOIDED`：沿用原有重建规则；超过期限且平台明确返回订单不存在时，也按既有规则核实后轮换。
- Capture 处理中、查询失败或状态不明确：停止重试并提示核实，不把超时直接当作未付款。

本站付款链接自身必须仍有效、订单仍为 `pending`；已关闭或已过期的本地订单不会重新开放。
恢复已批准的 Order 不延长原付款链接期限，也不延长记录中的平台会话期限。

运行 `php tests/paypal-funding-recovery.php` 验证拒付分类、恢复地址、并发保护和实际前端脚本的
restart 分支（前端脚本测试需要可运行的 Node.js，可通过 `NODE_BINARY` 指定路径）。
`gateway-session-state.php` / `payment-session-lifecycle.php` 同时覆盖重新打开链接、历史订单、
两小时后的原 Order 复用和不确定状态保护。真实 PayPal 沙箱的弹层及换付款来源流程仍需联调验证。

## 支付链接过期与到账补正（1.12.2）

`expired` 表示本站支付链接已过期，不代表支付平台一定没有收款。过期链接仍禁止重新发起支付，
不会因到账补正而重新开放；买家返回结果页时，对 `pending` / `expired` 订单先查询支付平台，
确认收款后再展示付款结果，尚未确认付款的 `pending` 订单才执行本地过期检查。

已验签的付款通知或主动查单确认到账时，允许 `expired → paid`，并按正常付款流程更新订单、
通知 S 系统。旧版因过期而忽略、但已经记录去重的付款事件，重新投递后也会再次验签和校验金额；
订单仍为 `expired` 才允许补正，重复投递不会重复触发同一次付款状态更新的副作用。

升级后，历史 `expired` 订单可调用 `POST /wp-json/payment-plugin/v1/order-query` 主动核实并补正，
无需删除事件去重记录或手工改回 `pending`。本次没有数据库表结构变更。

## 已付款订单交易号补全（1.12.3）

`POST /wp-json/payment-plugin/v1/order-query` 查询已为 `paid` 的订单时，如果本地
`transaction_id` 为空，而支付平台也确认已付款并返回非空交易号，会在现有金额/币种校验通过后
补写交易号并返回最新记录。PayPal 对应 Capture ID；已有交易号保留，平台未返回交易号、
查询失败或金额/币种不匹配时不会补写。

补全仅更新本地 `transaction_id` 和更新时间，不改 `paid_at`、支付会话标识或订单状态，
不重复标记 WooCommerce 订单付款，也不重复通知 S 系统。升级后，历史已付款但缺少交易号的订单
再次调用上述查询接口即可尝试补全，无需重新支付或重发付款通知。

## /v1/order-logs 订单日志查询接口（1.5.0 新增）

按 S 系统订单号返回该订单的全部日志记录（`wp_payment_logs` 表），供 S 系统排查一笔
订单的完整支付链路（下单、发起支付、Webhook 回调、取消/重试等）。认证要求与 `/v1/pay`
一致（订单级：WC API key 的 write/read_write，或订单接口账号的应用密码）。

**请求**：`POST /wp-json/payment-plugin/v1/order-logs`

```json
{ "s_order_id": "S20260902001" }
```

`s_order_id` 校验规则同 `/v1/pay`（只允许字母数字下划线中划线，≤64）；缺失或格式非法返回 `10005`。

**响应**（`data` 部分）：

```json
{
  "s_order_id": "S20260902001",
  "total": 1,
  "logs": [
    {
      "id": 123,
      "level": "INFO",
      "message": "买家取消了支付（user canceled）……",
      "payment_method": "paypal_js",
      "wp_order_id": 999001,
      "request_data": null,
      "response_data": { "reason": "user_cancel", "status": "pending" },
      "callback_data": null,
      "ip": "203.0.113.7",
      "user_agent": "Mozilla/5.0 ...",
      "created_at": "2026-09-02 08:15:30"
    }
  ]
}
```

- 日志按 `created_at DESC, id DESC` 倒序返回，单次上限 500 条（与后台日志页 `LogViewer` 一致）。
- `request_data` / `response_data` / `callback_data` 落库时是 JSON 字符串，接口里已解码成
  嵌套对象返回；这些字段在**写入时**就经过 `SensitiveDataFilter` 脱敏，不含密钥/卡号等敏感信息。
- `created_at` 为 UTC 时间，格式 `Y-m-d H:i:s`。
- 查不到日志不算错误：返回 `total: 0` + 空 `logs` 数组（订单号写错、或订单尚无日志都属正常）。

## 相对设计文档的实现取舍（重要，接手前请看一遍）

- **PayPal JS SDK**：用的是目前文档里仍广泛使用的经典 `paypal.Buttons()` 模式（`www.paypal.com/sdk/js`），
  没有用PayPal更新的v6 Web SDK——v6目前还缺乏足够的插件生态实践验证。如果PayPal后续明确停止支持经典模式，
  需要重新评估 `src/Gateways/PayPalJsGateway.php`。
- **Airwallex**：`AirwallexGateway.php` 里的Hosted Payment Page跳转URL格式，是按公开资料里常见的模式拼的，
  没能独立核实到当前最新、完整的query参数列表。Payment Intent创建接口本身（鉴权、核心字段）核实过，
  比较确定。**上线前务必用Airwallex沙箱账号跑一遍，对照他们的Hosted Payment Page文档确认URL格式**，
  必要时改用他们的Drop-in Element JS SDK替代纯跳转。
- **Antom**：认证方式（双向RSA签名）、大致的Pay API模式核实过，但请求体的具体字段名、
  webhook通知的string-to-sign具体怎么拼（尤其是里面用到的HTTP path，`WebhookController.php`
  里现在是个占位值）没有能够独立核实到最终确定的版本，代码里已经用注释标出来了。
  **这是5个网关里最需要在真实沙箱账号上验证的一个，不要直接用于生产。**
- **Stripe / PayPal REST**：这两个相对最有把握，Checkout Session、Orders v2
  API、webhook验签方式都是稳定、文档明确的部分。
- **支付页面会话缓存**：`/v1/pay` 仍预创建并缓存平台会话。Stripe / PayPal 在付款页先核实
  平台状态，未付款且达到本站会话轮换条件时创建新会话；有效会话继续复用。
  Airwallex / Antom 保持原缓存行为。本地付款链接期限与平台会话轮换期限分别管理，详见“平台会话期限与自动轮换”。

## 需要在各网关后台手动配置的东西（代码之外）

- Stripe：Dashboard → Webhooks，新增一个指向
  `https://your-site.com/wp-json/payment-plugin/v1/webhook/stripe` 的endpoint，
  或使用插件“设置”页显示的本站 Stripe 通知地址。保持 Snapshot payload 格式，订阅：
  `checkout.session.completed`、`checkout.session.async_payment_succeeded`、
  `checkout.session.async_payment_failed`、`payment_intent.succeeded`、
  `payment_intent.payment_failed`、`charge.refunded`、`charge.dispute.created`、
  `charge.dispute.updated`、`charge.dispute.closed`。
  其他事件（例如 `customer.discount.created`、`mandate.updated`、`payment_intent.created`）
  会直接返回 200 忽略，不按其对象 ID 查订单。
- PayPal：开发者后台给对应App订阅Webhook，URL使用插件后台“设置”页显示的
  对应支付模式的本站地址：PayPal JS 使用
  `.../wp-json/本站命名空间/通知路径/paypal_js`，PayPal REST 使用
  `.../wp-json/本站命名空间/通知路径/paypal_rest`。
  原来的共享入口 `.../wp-json/本站命名空间/通知路径/paypal` 及旧命名空间下的
  `.../wp-json/payment-plugin/v1/webhook/paypal`、`.../webhook/paypal_js`、
  `.../webhook/paypal_rest` 继续兼容；插件按交易记录选择实际通道和订单配置验签，
  同一事件通过这些入口投递时统一去重。
  修改现有Webhook的URL时保留原Webhook ID，订单验签使用创建订单时保存的`webhook_id`。
- Airwallex / Antom：同理，在各自商户后台把webhook/notify地址配置为
  `.../wp-json/payment-plugin/v1/webhook/airwallex` 和 `.../wp-json/payment-plugin/v1/webhook/antom`。

## 目录结构速览

```
src/Helpers/    与WordPress/业务无关的通用工具（加解密、日志过滤、HTTP客户端）
src/Core/       网关无关的核心业务逻辑（订单、交易、状态机、Webhook路由、重试队列……）
                其中 LineItemBuilder 是"按 trans_type 组装通道商品行"的唯一出处
src/Gateways/   每个支付网关的接入实现，只处理该网关特有的部分
src/API/        REST接口（Controllers）、权限校验（Middleware）、路由注册
src/Frontend/   面向匿名访客的支付页面（注意：不能叫Public，那是PHP保留字，无法作为命名空间）
src/Admin/      wp-admin后台页面
src/Models/     数据表名的唯一出处（表结构定义在Core/Migrator.php）
```

## 这次实现没有覆盖的部分

- 发起退款仍在各支付渠道操作，插件负责接收并回传已经发生的退款。
- 自动化测试：运行 `php tests/webhook-paypal-alias.php` 可在不连接WordPress数据库或
  支付网关的情况下验证PayPal共享Webhook入口、验签失败和跨入口去重。
  `php tests/payment-expiry.php` 验证链接过期、到账补正、历史会话引用、PayPal Capture 锁及并发状态更新。
  `php tests/gateway-session-state.php` 验证平台状态、Stripe 旧会话退役与创建幂等；
  `php tests/payment-session-lifecycle.php` 验证预创建、会话期限、轮换、日志和并发保护。
  其他状态迁移、`CurrencyManager` 及金额校验等逻辑仍需补充测试。
- 没有真实的三方网关沙箱账号可用于端到端联调，所有网关实现都是基于官方文档
  做的严谨实现，但**没有跑通过一次真实的sandbox请求**，接入前务必用沙箱账号
  逐个网关走一遍完整流程（创建订单→支付→webhook→状态更新→回调转发给S系统）。

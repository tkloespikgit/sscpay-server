# 关键技术决策及原因

核对日期：2026-09-28。以下是从现有代码及其注释整理出的决策记录，编号是本次文档编号，不代表原始实施时间。状态“已实现”只表示仓库中存在对应实现，不等于已在生产验证。已知文档差异与基线变化单独标注。

## D-01 分入口处理多租户授权

**状态：已实现。** 登录后台按 `User::manageableMerchantIds()` 应用全局 scope；无后台身份的 API/队列/CLI 显式限定商户；观察者按独立 guard 和渠道分配授权。

**原因与影响：** API 的身份来自 Application，不来自 session；系统级渠道又可能分配给多个商户，简单的 `merchant_id = 当前用户商户` 不能表达实际权限。渠道使用复用 `PaymentMethod::forMerchant()`，观察者查询不能继承 `User` 假设。

**依据：** [MerchantScope](../app/Models/Scopes/MerchantScope.php)、[PaymentMethod](../app/Models/PaymentMethod.php)、[观察者订单 Resource](../app/Filament/Observer/Resources/OrderResource.php)。

## D-02 三个下单入口共用一个业务服务

**状态：已实现。** API、手工建单、公开收款页调用 `OrderCreationService`，各适配层负责身份、表单和数据转换。

**原因与影响：** 金额校验、汇率、渠道路由、手续费快照和幂等规则必须一致。公开页面从服务端建单，避免把应用签名密钥下发浏览器；邮件派发交给调用方，Service 保存发信意图。

**依据：** [OrderCreationService](../app/Services/OrderCreationService.php)、[ManualOrderService](../app/Services/ManualOrderService.php)、[CheckoutLinkOrderService](../app/Services/Checkout/CheckoutLinkOrderService.php)。

## D-03 本地订单先提交，远端失败可补单

**状态：已实现。** 商户订单号级缓存锁串行化重复请求；本地订单与商品在事务内写入，HTTP 建品/建支付单在事务外执行。重复请求金额/币种相符且缺少 `pay_url` 时补建远端，沿用原 `s_order_id`。

**原因与影响：** 外部 HTTP 不应长期占有数据库事务。远端失败保留本地快照，不能重算汇率或换渠道后当作新单。插件侧同一 `s_order_id` 幂等是恢复链路的依赖；缓存锁租期是有限的，不能据此保证任意长的远端调用绝无并发。

**依据：** [OrderCreationService](../app/Services/OrderCreationService.php)、[OrderCreateRetryMailTest](../tests/Feature/OrderCreateRetryMailTest.php)。

## D-04 下单时锁定渠道，额度按渠道全局统计

**状态：已实现。** 普通路由先筛风控，再按“当日已成交额 / 组内权重”选最小值；平局按权重降序、ID 升序。限额统计按 `payment_method_id` 跨商户、系统时区和 `paid_at` 计算，历史空时间回退 `created_at`。

**原因与影响：** 分摊渠道负载，且共享渠道不能因商户不同的时区和范围重复获得额度。已发货/退款/拒付仍属于曾经支付成功，不能让这些状态释放成交额度。指定渠道是显式例外：跳过路由/限额，但不跳过商户可用范围、启用状态、有效支付组与渠道域名检查。

**依据：** [PaymentService](../app/Services/PaymentService.php)、[Order::paidEver()](../app/Models/Order.php)、[PaymentRiskPaidAtTest](../tests/Feature/PaymentRiskPaidAtTest.php)。

## D-05 下单域名策略

**状态：已实现（提交 `2186c3e`）。** `OrderCreationService::createOrderLocked()` 的普通下单应用域名检查被整段注释，旁注为“手动修改，不再验证域名的权限，放开客户对接”。文档整理开始时它是用户的未提交修改，最终核对时已进入 HEAD；这不是本次文档任务修改或提交的代码，也不应被理解为取消全部域名检查。

| 路径 | 当前行为 |
|---|---|
| 未指定渠道的 API/手工下单 | 不再在该段代码核对三个 URL 是否匹配 `applications.website`；入口自身的字段校验仍需看 FormRequest/表单 |
| 指定 `payment_method_key` | `resolveDesignatedPaymentMethod()` 仍要求三个 URL 与 `payment_methods.domain` 同域 |
| `checkout_link` | URL 由服务端生成，`notify_url=null`；公开页仍按链接允许的 Host 校验访问 |

**原因与影响：** 代码旁注给出的目的为放宽客户对接。旧 README/API 文档仍有应用域名限制叙述；后续任务先看 `git diff` 和实际实现，不能仅依据旧说明恢复限制。对外接口文档中的域名策略描述仍需同步；再次调整行为时更新此条。

**依据：** [OrderCreationService](../app/Services/OrderCreationService.php)、[CheckoutLinkOrderService](../app/Services/Checkout/CheckoutLinkOrderService.php)。

## D-06 账务集中处理，金额与费率使用快照

**状态：已实现。** `BalanceService` 是余额与冻结额的统一写入口，先锁商户再处理业务记录；总余额变化写台账，自动资金动作有唯一幂等键。下单保存汇率、汇损与交易手续费，支付入账使用净额 `settlement_amount`。

**原因与影响：** 避免并发丢账、重复入账和配置变更追溯改变历史金额。成交额指标使用 `converted_amount`，退款使用原币种金额按订单快照比例折算，两者不能当作净入账直接互换。人工动作还需权限与 TOTP 验证。

**依据：** [BalanceService](../app/Services/BalanceService.php)、[FinanceSecurity](../app/Filament/Support/FinanceSecurity.php)、[OrderCreationService](../app/Services/OrderCreationService.php)。

## D-07 网关退款和拒付自动记账，保留人工补录

**状态：已实现，替代旧版“只改状态、人工扣款”的行为。** `refunded` 自动按本地剩余可退金额退款，`confused` 映射 `chargeback` 并自动尝试扣款；金额依据本地账面，不直接信任 webhook 的订单金额为退款额。

**原因与影响：** 旧路径会留下“状态已退款但余额未扣”的记录，且人工按钮可能被终态限制挡住。现通过状态守卫与流水幂等保护自动扣款；业务性失败告警并支持补录。资金事务放在状态事务之后，维持商户→订单锁顺序，不能随意嵌套反向加锁。

**依据：** [OrderPaymentStatusService](../app/Services/OrderPaymentStatusService.php)、[GatewayRefundChargebackTest](../tests/Feature/GatewayRefundChargebackTest.php)。

## D-08 网关争议与人工审核分开

**状态：已实现；主动查询的 `paid` 例外由 D-17 补充。** 网关 `disputing` 与人工 `dispute_review` 是两套机制；人工审核期间忽略 webhook 状态覆盖并可告警。主动查询返回 `paid` 可纠正订单状态，审核事件与冻结独立保留。审核开立/关闭冻结或释放快照金额，拟定的 `final_action` 不自动执行退款/拒付。

**原因与影响：** 人工收集材料及截止期限不能被异步网关消息打断。单订单同时最多一个处理中事件，由行锁与生成列唯一约束共同保护。

**依据：** [OrderDisputeService](../app/Services/OrderDisputeService.php)、[BalanceService](../app/Services/BalanceService.php)、[争议迁移](../database/migrations/2026_09_03_000007_create_order_dispute_events_table.php)。

## D-09 软删除的唯一性由生成列表达

**状态：已实现。** 有效记录映射业务键，软删除记录映射 NULL 后参与唯一索引；人工审核以 `processing` 状态构造唯一条件。渠道代码经后续迁移改为有效记录全局唯一。

**原因与影响：** 允许软删后重用业务键，同时让数据库兜底并发冲突；直接把 `deleted_at` 放进复合唯一索引不能达到同样效果。迁移包含 MySQL 专有语法，需使用目标数据库验证。

**依据：** [订单迁移](../database/migrations/2026_07_05_000010_create_orders_table.php)、[渠道代码唯一性迁移](../database/migrations/2026_09_21_000002_make_method_code_globally_unique_in_payment_methods_table.php)。

## D-10 统计按事件时间归日，整日重建汇总

**状态：已实现。** 付款、失败、退款、拒付分别按各自事件时间统计；每日事务内删旧桶再插入，独立执行记录区分零交易与未统计。滚动重算近期日期补偿延迟回调与漏跑。

**原因与影响：** 下单日期不能代表后续退款日期；仅 upsert 无法清除源记录消失后的旧汇总。统计使用系统时区和 USD 成交口径，不随展示时区或净手续费变化。超出滚动范围的历史修正需要指定日期重算。

**依据：** [OrderStatsAggregator](../app/Services/OrderStatsAggregator.php)、[AggregateOrderStats](../app/Console/Commands/AggregateOrderStats.php)、[统计测试](../tests/Feature/OrderStatsAggregatorTest.php)。

## D-11 外部通知用尝试记录控制重试

**状态：已实现。** 商户回调和广告转化的 Job 本身 `tries=1`；失败记录 `next_retry_at`，调度扫描后创建后续尝试。付款邮件等其他任务另有 Job 级重试。

**原因与影响：** 避免队列重试与业务重试叠加，保留每次请求与响应的审计信息。新增失败处理应沿用尝试表机制，不同时添加多套自动重试。

**依据：** [OrderNotificationService](../app/Services/OrderNotificationService.php)、[AdConversionService](../app/Services/AdConversionService.php)、[routes/console.php](../routes/console.php)。

## D-12 三种签名/认证各自保留协议

**状态：已实现。** 商户入站与通知共用 `SignatureCanonicalizer`（元信息加递归规范化 JSON 的 HMAC）；插件 webhook 用独立 secret 对原始 body 验签；出站插件客户端当前通过 `consumer_key`/`consumer_secret` query 参数认证。

**原因与影响：** 商户签名需保留列表顺序，不能把空字符串在验签前改成 null；插件不接受标准 Basic Auth，query 方式避开部分代理丢失 Authorization 头的问题。旧注释里的 Basic Auth/明文 Authorization 不是当前客户端实现。

当前客户端还调用 `withoutVerifying()`，这是现存行为记录，不是新增集成必须照用的约定。该设置及 URL 凭证涉及的传输/日志处理应在网关集成任务中专门核对。

**依据：** [SignatureCanonicalizer](../app/Support/SignatureCanonicalizer.php)、[ApiAuthentication](../app/Http/Middleware/ApiAuthentication.php)、[PaymentGatewayService::client()](../app/Services/PaymentGateway/PaymentGatewayService.php)。

## D-13 可复用收款链接与订单付款链接分开

**状态：已实现。** `/c/{slug}` 配置商品/金额并创建多笔订单；`/payment/{token}` 服务已有订单。公开收款页按 slug+Host 定位、服务端构造下单数据、每次提交生成新商户订单号，回跳结果页不更新支付状态。

**原因与影响：** 不同客户不能因重复访问同一链接复用别人的订单；金额、商户身份和回跳地址由服务端控制，支付结果由已验证网关状态驱动。Cloudflare 证书异步签发，域名是否就绪由模型状态和定时同步配合处理。

**依据：** [routes/checkout.php](../routes/checkout.php)、[CheckoutLinkOrderService](../app/Services/Checkout/CheckoutLinkOrderService.php)、[CheckoutLinkController](../app/Http/Controllers/CheckoutLinkController.php)、[CheckoutLinkTest](../tests/Feature/CheckoutLinkTest.php)。

## D-10 自动匹配尾件保留原价，超额计入订单折扣

- 状态：采用（2026-09-30，按用户要求）。
- 原因：商品行展示真实价格，折扣集中体现在订单上。
- 实现：`OrderItemService::matchItems()` 返回原价明细和超额；`OrderCreationService` 同步增加小计与折扣并保留应付金额，重试还原原始目标后重新计算。
- 影响：不再追加商品名称折扣文案，移除尾件改价回溯；旧配置 `order_match.min_price_ratio` 不再参与匹配。随机件数上限、容量限制仍有效。按订单币种单价匹配，避免先以 USD 凑满再逐行换汇导致短缺。

## D-14 应用自动折扣保存原始金额与减免快照

**状态：当前工作区已实现，未提交（2026-09-30）。** 应用开关默认关闭。自动折扣在统一建单入口验证原始请求后、计算汇率与费用前应用，计入 `discount` 并减少 `amount`；与匹配商品溢出折扣叠加。

**原因与影响：** 实际付款金额改变后，不能继续用它与重试请求金额直接比较。新增 `original_amount` 用于幂等、`auto_discount` 用于审计；历史单原始金额为空时沿用 `amount`。折扣仅首次生成，应用开关变化不影响旧订单；查询、通知、手续费、结算按实际折后金额处理。

**依据：** [OrderCreationService](../app/Services/OrderCreationService.php)、[迁移](../database/migrations/2026_09_30_000001_add_application_auto_discount.php)、[ApplicationAutoDiscountTest](../tests/Feature/ApplicationAutoDiscountTest.php)。规则及小额边界见 [数据库说明](database.md)。

## D-15 支付网关 webhook 不限定 API 域名

**状态：当前工作区已实现，未提交（2026-10-13，按用户要求）。** `API_DOMAIN` 仅限制商户下单、查询、发货接口；`/api/webhooks/payment-gateway/status` 在域名组外注册，保留 `X-PGA-Signature` 对原始请求体的 HMAC 校验。

**原因与影响：** `OrderCreationService` 通过当前请求的 URL 构造 `callback_url`，收款链接建单时可能使用客户自定义域名。webhook 若限定 API 域名，插件回调会在路由匹配阶段返回 404，无法落地订单结果。允许其他域名到达验签入口，商户 API 的域名限制和鉴权保持原有行为；部署变更需更新路由缓存。

**依据：** [routes/api.php](../routes/api.php)、[OrderCreationService](../app/Services/OrderCreationService.php)、[PaymentGatewayWebhookController](../app/Http/Controllers/PaymentGatewayWebhookController.php)、[PaymentGatewayWebhookRoutingTest](../tests/Feature/PaymentGatewayWebhookRoutingTest.php)。

## D-16 漏配汇损使用专用命令纠正订单，不重放资金入账

**状态：当前工作区已实现，未提交（2026-10-14）。** 用户要求按商户用最新汇损补算历史订单并自行调整余额。命令 `orders:recalculate-surcharge` 保留各单原始市场汇率、历史交易手续费金额与原币金额，计算新的实际汇率和订单金额快照；默认预览，显式 `--apply` 写入并保留逐单新旧报告。

**原因与影响：** 不能把当前市场行情当作历史市场价，也无法从已截断的历史手续费金额精确还原百分比费率。仅处理已入账、无退款/争议历史且原资金流水与净入账快照相符的订单；其余列原因跳过。原余额和台账均不改，显示“含汇损与无汇损之差”以及“本次净入账变化”两个口径，后者作为此次人工调账参考。历史统计需按报告日期另行补算，正常新单的快照规则不变。

**依据：** [RecalculateOrderSurcharge](../app/Console/Commands/RecalculateOrderSurcharge.php)、[OrderSurchargeRecalculationService](../app/Services/OrderSurchargeRecalculationService.php)、[RecalculateOrderSurchargeTest](../tests/Feature/RecalculateOrderSurchargeTest.php)、[操作手册](../doc/order-surcharge-recalculation.md)。

## D-17 主动查询已支付结果优先于本地状态

**状态：当前工作区已实现，未提交（2026-10-17，按用户要求）。** `OrderPaymentStatusService::queryAndApply()` 查询网关返回 `paid` 时，无论本地是过期、失败、退款、拒付、已完成或人工审核等状态，都纠正为 `paid`；本地已为 `paid` 时幂等跳过。此例外补充 D-08，webhook 与查询非 `paid` 结果继续使用原有状态守卫。

**原因与影响：** 主动核对确认付款后，本地终态不能阻止支付状态纠正。从未支付状态恢复 `paid` 复用 `BalanceService` 入账幂等与首次通知；曾收款订单仅纠正状态，不重复入账、商户通知、广告转化或指定渠道付款提醒。首次支付/失败时间、退款金额、资金台账和人工审核冻结均保留，不将状态纠正解释为撤销退款/拒付或关闭审核。已存在拒付本金流水时，无论当前状态如何，都禁止再次退款或拒付，防止纠正状态后重复扣款。

商户 `/api/order/query` 仍只读取本地状态；定时补查仍只选择最近 48 小时未收款的 `pending` 订单，不因本规则扩大扫描范围。

**依据：** [OrderPaymentStatusService](../app/Services/OrderPaymentStatusService.php)、[BalanceService](../app/Services/BalanceService.php)、[SendTelegramNotification](../app/Listeners/SendTelegramNotification.php)、[查询回归测试](../tests/Feature/OrderPaymentStatusQueryTest.php)、[退款/拒付测试](../tests/Feature/GatewayRefundChargebackTest.php)。

## D-18 待支付订单按创建时间最多自动补查两次

**状态：当前工作区已实现，未提交（2026-10-17，按用户要求）。** 最近 48 小时的 `pending` 且 `paid_at` 为空的订单，分别在创建满 30 分钟、90 分钟时获得一次定时补查机会，每单最多两次。调度由每 30 分钟执行改为每分钟扫描，避免整点/半点调度使任意创建时间的订单晚查近半小时；繁忙或停机时在窗口内顺延，每轮每单最多补一次。

**原因与影响：** 避免未付款订单在 48 小时内反复请求网关。订单持久化 `pending_status_query_count` 与 `pending_status_last_queried_at`，在网关调用之前按当前状态、窗口、到期条件及原次数原子领取，异常、未知状态和缺凭证都消耗一次，重复或并发调用不会突破两次上限。第一次查询后已离开待支付状态则停止；手工查询不计入自动额度，客户端内部连接重试仍沿用原策略。

首次部署须执行新增迁移。此前没有可恢复的查询计数，历史订单默认从 0 累计并保留 48 小时窗口，不从日志推断次数；回滚/重建计数会重开额度，生产优先前向修复。本规则仅改变定时查询的选择和次数，支付状态落地继续遵循 D-17。

**依据：** [PendingOrderStatusSyncService](../app/Services/PendingOrderStatusSyncService.php)、[Order](../app/Models/Order.php)、[迁移](../database/migrations/2026_10_17_024742_add_pending_status_query_tracking_to_orders_table.php)、[调度](../routes/console.php)、[回归测试](../tests/Feature/SyncPendingOrderStatusesTest.php)。

## D-19 后台表格导出使用 XLSX，非金额字段保存为文本

**状态：当前工作区已实现，未提交（2026-10-22，按用户要求）。** 物流模板和观察者订单列表导出改为 XLSX；除订单金额外，所有字段及空填写格均使用文本单元格和文本格式。金额保留数值类型、两位小数和原有观察者显示比例。

**原因与影响：** CSV 不能指定单元格类型，Excel 自动推断会使纯数字编号显示为科学计数、丢失前导零或长数字精度。统一使用已安装的 OpenSpout 按行写出 XLSX，显式字符串单元格也能保留公式样式文本的原值；不添加公式、单引号或制表符来伪装文本。导出保留查询筛选、商户及观察者权限范围，物流 XLSX 可填写后直接上传，旧 CSV/XLS 导入仍支持。物流模板最多容纳 1,048,574 条订单及两行表头/说明，超限拒绝下载并提示分批筛选，防止自动分表后上传遗漏后续工作表。仅改变文件表示，不修改数据库中的编号或金额。

**依据：** [XlsxExport](../app/Support/XlsxExport.php)、[LogisticsImportService](../app/Services/LogisticsImportService.php)、[ObserverOrderExportService](../app/Services/ObserverOrderExportService.php)、[物流导出与回传测试](../tests/Feature/LogisticsTemplateTest.php)、[观察者导出测试](../tests/Feature/Filament/ObserverOrdersTest.php)。

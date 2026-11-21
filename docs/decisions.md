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

**状态：已实现；失败及重试处理现遵循 [D-24](#d-24-网关建单失败立即返回与原渠道重试)，替代 [D-20](#d-20-前三候选随机路由与同请求网关故障切换) 的渠道切换。** 商户订单号级缓存锁串行化重复请求；本地订单与商品在事务内写入，HTTP 建品/建支付单在事务外执行。重复请求金额/币种相符且缺少 `pay_url`、尚未收款、状态为 `pending/failed` 时，重新校验原渠道后沿用已有 `s_order_id` 补建，不重选渠道。

**原因与影响：** 外部 HTTP 不应长期占有数据库事务。远端失败保留本地订单、原始金额、汇率、渠道、远端引用和财务快照，返回失败后由调用方重试。插件侧同一 `s_order_id` 幂等是恢复链路的依赖；缓存锁租期是有限的，不能据此保证任意长的远端调用绝无并发。

**依据：** [OrderCreationService](../app/Services/OrderCreationService.php)、[OrderCreateRetryMailTest](../tests/Feature/OrderCreateRetryMailTest.php)。

## D-04 下单时锁定渠道，额度按渠道全局统计

**状态：已实现；单一最小负载选择由 [D-20](#d-20-前三候选随机路由与同请求网关故障切换) 的前三候选随机规则替代。** 普通路由继续先筛风控，再按“当日已成交额 / 组内权重”升序排列；平局按权重降序、ID 升序。限额统计按 `payment_method_id` 跨商户、系统时区和 `paid_at` 计算，历史空时间回退 `created_at`。

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

**状态：已实现；原主动查询例外由 D-18 补充，2026-11-06 的状态恢复与最终反向结果处理由 [D-29](#d-29-订单主状态由结算与售后事实统一推导) 替代。** 网关 `disputing` 与人工 `dispute_review` 仍是两套机制；审核期间的普通支付状态不能抹掉活动审核，最终网关退款/拒付则保存并可靠记账，与审核冻结协调处理。关闭审核按独立事实恢复主状态，不再固定回 `paid`。审核拟定的 `final_action` 本身仍不触发退款/拒付。

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

## D-17 商户独立财务配置、订单结算与保证金

默认配置复制及逐渠道结算的后续扩展见 [D-22](#d-22-商户默认财务配置按分配复制)。

**状态：用户已确认；当前工作区实现，未提交（2026-10-16）。** 商户有权使用的每个渠道可独立覆盖财务条件，空值继承默认；财务参数由超管/所属管理员维护，商户只读。新单保存手续费、结算、保证金快照，后续改配置不追溯；历史订单、余额和流水不重算。

支付成功按净额计入总额及待结算。商户选择支付成功或首次有效发货起算，按 N×24 小时到期；结算时按净额分入循环保证金与可提现，锁定期从实际结算开始，0 天不冻结。人工固定保证金只允许冻结可提现。争议期间暂停本单结算/循环保证金释放，恢复后保留原到期时间。

退款/拒付本金依次扣本单待结算、本单循环保证金、可提现，额外手续费直接扣可提现，原交易手续费不返还。不自动挪用固定保证金、其他订单专属资金和待出款；不足允许负可提现并禁止新增提现/确认放款。提现申请占用待出款，后台确认放款即扣总额与占用，驳回恢复可提现。

**原因与影响：** 同渠道不同商户需要不同财务条件，支付成功与可提现需要分开。余额分类保持同一商户行事务更新并写划转台账；现存提现以读时归类兼容，不回填原冻结额。新增可靠记账待办恢复状态已提交但账务失败的场景，统一商户优先锁序；扫描分批限量，每 10 秒后台执行，以正常负载一分钟内处理为目标，实际峰值容量仍需实测。

**依据：** [MerchantPaymentSettingsService](../app/Services/MerchantPaymentSettingsService.php)、[OrderCreationService](../app/Services/OrderCreationService.php)、[BalanceService](../app/Services/BalanceService.php)、[OrderAccountingService](../app/Services/OrderAccountingService.php)、[OrderSettlement](../app/Models/OrderSettlement.php)、[调度](../routes/console.php)。

## D-18 主动查询已支付结果优先于本地状态

**替代关系：2026-11-06 的 [D-29](#d-29-订单主状态由结算与售后事实统一推导) 已替代下述“`paid` 无条件改为本地 `paid/shipped`”规则。以下保留为历史实现记录；当前查询确认支付事实，再结合结算及售后事实推导主状态。**

**状态：当前工作区已实现，未提交（2026-10-17，按用户要求）。** `OrderPaymentStatusService::queryAndApply()` 查询网关返回 `paid` 时，无论本地是过期、失败、退款、拒付、已完成或人工审核等状态，都纠正为 `paid`；本地已为 `paid` 时幂等跳过。此例外补充 D-08，webhook 与查询非 `paid` 结果继续使用原有状态守卫。

2026-11-03 工作区修正：曾收款订单恢复 `paid` 时，有本商户未软删除的物流记录则恢复为 `shipped`，避免争议取消后误回到待发货。查询和允许落地的 webhook 共用此规则，按最终本地状态判重；已被旧逻辑改成 `paid` 且仍有有效物流的订单再次查询即可纠正。首次支付沿用原有入账、通知及预录物流推进流程。

**原因与影响：** 主动核对确认付款后，本地终态不能阻止支付状态纠正。从未支付状态恢复 `paid` 复用 `BalanceService` 入账幂等与首次通知；曾收款订单仅纠正状态，不重复入账、商户通知、广告转化或指定渠道付款提醒。首次支付/失败时间、退款金额、资金台账和人工审核冻结均保留，不将状态纠正解释为撤销退款/拒付或关闭审核。已存在拒付本金流水时，无论当前状态如何，都禁止再次退款或拒付，防止纠正状态后重复扣款。

商户 `/api/order/query` 仍只读取本地状态；定时补查仍只选择最近 48 小时未收款的 `pending` 订单，不因本规则扩大扫描范围。

**依据：** [OrderPaymentStatusService](../app/Services/OrderPaymentStatusService.php)、[BalanceService](../app/Services/BalanceService.php)、[SendTelegramNotification](../app/Listeners/SendTelegramNotification.php)、[查询回归测试](../tests/Feature/OrderPaymentStatusQueryTest.php)、[退款/拒付测试](../tests/Feature/GatewayRefundChargebackTest.php)。

## D-19 待支付订单按创建时间最多自动补查两次

**状态：当前工作区已实现，未提交（2026-10-17，按用户要求）。** 最近 48 小时的 `pending` 且 `paid_at` 为空的订单，分别在创建满 30 分钟、90 分钟时获得一次定时补查机会，每单最多两次。调度由每 30 分钟执行改为每分钟扫描，避免整点/半点调度使任意创建时间的订单晚查近半小时；繁忙或停机时在窗口内顺延，每轮每单最多补一次。

**原因与影响：** 避免未付款订单在 48 小时内反复请求网关。订单持久化 `pending_status_query_count` 与 `pending_status_last_queried_at`，在网关调用之前按当前状态、窗口、到期条件及原次数原子领取，异常、未知状态和缺凭证都消耗一次，重复或并发调用不会突破两次上限。第一次查询后已离开待支付状态则停止；手工查询不计入自动额度，客户端内部连接重试仍沿用原策略。

首次部署须执行新增迁移。此前没有可恢复的查询计数，历史订单默认从 0 累计并保留 48 小时窗口，不从日志推断次数；回滚/重建计数会重开额度，生产优先前向修复。本规则仅改变定时查询的选择和次数，支付状态落地当前遵循 D-29；此前的 D-18 规则保留为历史记录。

**依据：** [PendingOrderStatusSyncService](../app/Services/PendingOrderStatusSyncService.php)、[Order](../app/Models/Order.php)、[迁移](../database/migrations/2026_10_17_024742_add_pending_status_query_tracking_to_orders_table.php)、[调度](../routes/console.php)、[回归测试](../tests/Feature/SyncPendingOrderStatusesTest.php)。

## D-19 后台表格导出使用 XLSX，非金额字段保存为文本

**状态：当前工作区已实现，未提交（2026-10-22，按用户要求）。** 物流模板和观察者订单列表导出改为 XLSX；除订单金额外，所有字段及空填写格均使用文本单元格和文本格式。金额保留数值类型、两位小数和原有观察者显示比例。

**原因与影响：** CSV 不能指定单元格类型，Excel 自动推断会使纯数字编号显示为科学计数、丢失前导零或长数字精度。统一使用已安装的 OpenSpout 按行写出 XLSX，显式字符串单元格也能保留公式样式文本的原值；不添加公式、单引号或制表符来伪装文本。导出保留查询筛选、商户及观察者权限范围，物流 XLSX 可填写后直接上传，旧 CSV/XLS 导入仍支持。物流模板最多容纳 1,048,574 条订单及两行表头/说明，超限拒绝下载并提示分批筛选，防止自动分表后上传遗漏后续工作表。仅改变文件表示，不修改数据库中的编号或金额。

**依据：** [XlsxExport](../app/Support/XlsxExport.php)、[LogisticsImportService](../app/Services/LogisticsImportService.php)、[ObserverOrderExportService](../app/Services/ObserverOrderExportService.php)、[物流导出与回传测试](../tests/Feature/LogisticsTemplateTest.php)、[观察者导出测试](../tests/Feature/Filament/ObserverOrdersTest.php)。

## D-20 前三候选随机路由与同请求网关故障切换

**状态：历史实现（2026-10-28）；其中故障切换规则于 2026-11-03 按用户要求由 [D-24](#d-24-网关建单失败立即返回与原渠道重试) 替代，首次前三候选随机及历史远端引用兼容仍保留。** 以下记录当时对 D-03 的渠道固定和远端引用规则、D-04 的单一最小负载选择所作的调整；当前请求失败后即返回，后续重试也沿用原渠道，不再切换。商户幂等、原金额/汇率快照、渠道授权及统计口径保持。

普通组内路由先筛选启用、商户可使用且通过限额风控的渠道，再沿用“当天已成交金额 / 组内权重”升序、权重降序、ID 升序，从前三个候选中等概率随机选择；不足三个时全部参与。待支付订单仍不计成交负载，随机结果可能连续相同，不承诺逐单轮转或严格的成交占比。

网关失败时在同一次下单请求内排除已尝试渠道，再按相同规则重选；`payment_gateway.failover_max_attempts` 默认 3，含首选渠道。候选耗尽或达到上限时返回最后的网关异常并保留本地订单；不会将故障排除后的耗尽当成初次风控失败发送提醒。指定 `payment_method_key` 的订单保留直连语义，不自动切换；成功取得支付链接后，幂等请求直接复用。

**原因与影响：** 仅看已成交金额时，尚未付款的连续订单会因排名不变集中到同一渠道；随机候选让新流量分散，当前请求内切换减少商户手工重试。每次切换都重新检查渠道可用性和限额，在同一本地订单上更新渠道、交易手续费、净入账、保证金及退款/拒付手续费快照，并清除旧匹配商品重新准备。原始商品、金额、汇率和首次自动折扣保持，不重复建立本地订单或产生资金流水。

`orders.gateway_order_no` 保存可空且唯一的当前远端引用，切换时重新生成；`Order::gatewayOrderReference()` 对 NULL 回退本地 `order_no`。插件 `/pay`、查询、日志和物流调用统一使用当前引用；webhook 与主动查询在状态落地前再次核对引用，防止旧尝试的迟到结果改变新渠道订单或触发其账务。商户侧订单号、支付 token 和协议字段保持不变。新引用用于隔离尝试，不能代替网关的远端取消接口；超时仍可能意味着旧站点已经创建过未返回链接的订单。

事件日志将网关尝试加入生成列唯一约束，保留旧渠道和新渠道相同外部日志 ID 的不同记录。首次部署须执行订单和事件日志两项迁移；历史订单引用保持 NULL，历史日志来源保持空字符串，不改写历史远端编号。已有尝试引用或来源日志时拒绝破坏性回滚。回归测试使用模拟 HTTP，不触发真实建单或账务。

**依据：** [PaymentService](../app/Services/PaymentService.php)、[OrderCreationService](../app/Services/OrderCreationService.php)、[Order](../app/Models/Order.php)、[OrderPaymentStatusService](../app/Services/OrderPaymentStatusService.php)、[网关配置](../config/payment_gateway.php)、[迁移](../database/migrations/2026_10_28_142012_add_gateway_order_no_to_orders_table.php)、[路由测试](../tests/Feature/PaymentRoutingTest.php)、[故障切换测试](../tests/Feature/OrderCreationFailoverTest.php)。

## D-21 付款链接邮件统一使用应用发信配置

**状态：当前工作区实现，未提交（2026-11-03，按用户要求，含后续移除支付方式正文的补充要求）。** `Order::resolveMailSender()` 只使用订单所属商户的应用发件人、发信驱动和完整凭证；取消支付方式优先选择。应用关闭邮件则跳过，缺配置则由 `SendPaymentLinkJob` 记录发送失败，不回退支付方式历史配置或平台账号。`resolveMailTemplate()` 只使用应用正文，留空则使用系统默认模板。

**原因与影响：** API 通过应用鉴权，选择 `send_mail=Y` 后，客户邮件应使用该应用配置的发信账号和正文，避免路由到不同支付方式时改变邮件设置。后台发信账号配置集中在应用编辑页顶部“邮件发送”，正文在应用表单编辑；支付方式移除邮件配置入口、正文表单与相关列表列。手工建单及重发沿用同一 Job；API 发信意图、`payment-links` 队列、补单成功后派发与幂等规则保持。

应用已有配置直接沿用。支付方式同名五列仅保留历史数据和密文解读兼容，移出批量赋值且复制渠道不携带，不修改已执行迁移或自动跨应用迁移邮件设置；原先仅在支付方式配置的账号与正文需在对应应用重新配置。

**依据：** [Order](../app/Models/Order.php)、[SendPaymentLinkJob](../app/Jobs/SendPaymentLinkJob.php)、[MailCredentialsAction](../app/Filament/Support/MailCredentialsAction.php)、[PaymentMethodResource](../app/Filament/Resources/PaymentMethodResource.php)、[邮件发送测试](../tests/Feature/SendPaymentLinkJobTest.php)、[API 重试邮件测试](../tests/Feature/OrderCreateRetryMailTest.php)。

## D-22 商户默认财务配置按分配复制

**状态：当前工作区实现，未提交（2026-11-03，按用户要求）。** 创建/编辑商户与入驻向导提供默认手续费、结算模式/周期及保证金比例/锁定天数。新分配系统级支付方式时，将商户当时的默认值复制到 `merchant_payment_settings`，之后可在商户编辑页按支付方式独立修改。交易、退款及拒付手续费均使用同一默认配置入口。

**原因与影响：** 商户默认值是首次分配的模板，分配后的独立值不随默认值变化；已有配置及取消后重新分配保留，不批量覆盖历史。分配关系与配置初始化共用事务，先锁商户再写分配；配置唯一约束和 `firstOrCreate` 防止覆盖。商户自有渠道保留既有覆盖入口与回退规则。2026-11-03 后续按用户反馈补充：保存商户时为该商户已有有效分配中缺失的配置补齐默认值，避免存量分配一直回退渠道费率；已有记录（含空值或 0）不改写，不后台扫描全平台。

2026-11-04 按用户后续要求，**商户编辑弹窗**取消授权时保留配置的规则由 [D-27](#d-27-商户编辑页集中授权系统支付渠道) 替代：同步删除对应独立财务配置，重新授权使用商户当时的默认值。原支付方式编辑页及直接 Pivot 解除关系仍保留配置，不将删除钩子扩散到系统渠道转商户自有等旧路径。 2026-11-11 的 [D-32](#d-32-支付方式管理员边界与点选授权) 又将支付方式编辑页改为点选授权，其明确撤销也清理独立配置；直接 Pivot 解除关系继续保留。

本项扩展 [D-17](#d-17-商户独立财务配置订单结算与保证金)：结算模式和周期也允许逐渠道设置。新订单从选中渠道的独立配置生成全部财务快照；手续费/保证金空值回退渠道默认，结算空值回退商户默认。[D-24](#d-24-网关建单失败立即返回与原渠道重试) 的原渠道重试不重算已保存快照，已有支付链接、已收款及历史订单亦不重算。新迁移只添加商户默认列和独立配置结算列，不改既有余额或账务记录。

**依据：** [MerchantPaymentSettingsForm](../app/Filament/Support/MerchantPaymentSettingsForm.php)、[MerchantPaymentMethod](../app/Models/MerchantPaymentMethod.php)、[MerchantPaymentSettingsService](../app/Services/MerchantPaymentSettingsService.php)、[PaymentMethod](../app/Models/PaymentMethod.php)、[OrderCreationService](../app/Services/OrderCreationService.php)、[分配回归测试](../tests/Feature/MerchantPaymentDefaultsAssignmentTest.php)、[独立结算表单测试](../tests/Feature/MerchantPaymentSettlementOverridesTest.php)。

## D-23 仪表盘统一读取每日统计与时间快捷切换

**状态：日期和统计口径继续沿用，筛选维度由 [D-69](#d-69-仪表盘统一筛选与统计明细) 更新。** 仪表盘使用当天、昨天、近 7/30 天、本月、上月及自定义快捷按钮，自定义区间通过弹窗确认。全部交易卡片、排名与图表共用所选窗口，订单数与 USD 成交额分成独立趋势图。

**原因与影响：** 仪表盘交易数据统一从 `order_daily_stats` 读取，沿用 [D-10](#d-10-统计按事件时间归日整日重建汇总) 的事件归日规则。新增 `created_orders` 按创建日统计全部未删除订单，`unpaid_terminal_orders` 统计失败、取消、过期，排除待支付；支付成功率分母为支付成功数加未支付终态数，退款和拒付不重复加入。失败按失败日、取消/过期按创建日；创建单量与支付金额在跨日付款时可以出现在不同日期。

按系统时区，日期区间包含首尾；近 7/30 天含当天，本月截至当天。新聚合在同一事务提交数据及执行版本 2，旧执行记录保留为版本 1 并提示需重算；不在仪表盘请求中回查订单或自动聚合，调度频率保持不变。交易商户数按所选窗口发生支付成功的商户去重，交易指标与实时余额分别取数。

商户用户具备 `finance.view` 才展示当前商户的余额分类，独立于日期筛选；组件每次取数检查权限、用户和商户启用状态，按登录账号确定商户，复用现有旧提现占用分类。平台账号不显示单商户余额卡，不能通过隐藏组件的直接请求绕过权限。

**依据：** [AdminDashboard](../app/Filament/Pages/AdminDashboard.php)、[DashboardService](../app/Services/DashboardService.php)、[OrderStatsAggregator](../app/Services/OrderStatsAggregator.php)、[OrderStatsRun](../app/Models/OrderStatsRun.php)、[MerchantBalanceOverview](../app/Filament/Widgets/MerchantBalanceOverview.php)、[页面测试](../tests/Feature/AdminDashboardTest.php)、[组件测试](../tests/Feature/DashboardWidgetsTest.php)、[统计口径测试](../tests/Feature/DashboardOrderStatsAlignmentTest.php)。

## D-24 网关建单失败立即返回与原渠道重试

**状态：当前工作区实现，未提交（2026-11-03，按用户最终澄清）。** 替代 [D-20](#d-20-前三候选随机路由与同请求网关故障切换) 的故障切换规则。普通路由首次仍从通过可用性与风控检查、按负载排序的前三个候选中随机选一个；网关建单或明确的商品准备失败后，本次请求立即停止，返回“建单失败，请重试”，不再尝试其他渠道。后续同商户单号重试保留，只沿用原渠道，不切换到其他通道。

**原因与影响：** 用户要求只移除“重试切换其他通道”，保留原渠道补建。币种及原始金额一致、无付款链接、未收款且为 `pending/failed` 的订单重试时，必须重新校验原渠道仍启用、未软删且商户有权使用。普通路由还要求原支付组有效、原渠道仍在组内并通过当前风控；不合格返回 `NO_AVAILABLE_PAYMENT_METHOD`，即使其他渠道可用也不另选。已有付款链接、已收款或已离开可建单状态时返回现有订单，不继续远端创建。

重试模式以订单已保存的支付组、`designated_payment_method_key`、渠道 ID 及回跳 URL 为准，不采纳新请求的 `group_key` / `payment_method_key` 或 URL 改变原订单。指定渠道重试重新校验原渠道 ID 的商户授权、启用、软删状态及保存的三个 URL，失效返回 `PAYMENT_METHOD_NOT_AVAILABLE`，域名不符返回 `PAYMENT_METHOD_DOMAIN_MISMATCH`；仍保留跳过普通组内风控的语义。

重试保留同一本地订单、`order_no`、付款 token、原渠道、远端引用、全部财务快照、原始商品、应付金额、汇率及首次自动折扣；CREATE/COPY 复用此前已创建的商品。历史 `payment_method_id` 为空时仅补齐同一渠道关联，不改引用或金融快照。写链接前继续在行锁内核对当前引用与渠道，建单前和失败后仍检查支付时间、付款链接及状态，保留并发收款/链接结果。

删除 `payment_gateway.failover_max_attempts` 配置，订单号缓存锁按单渠道 HTTP 重试和同步商品准备预算计算；同渠道连接失败的 HTTP 重试仍沿用现有 `retry_times` / `retry_sleep_ms`。历史切换产生的网关引用、日志来源及迟到回调的引用核对继续保留，不删除金融审计数据、不增加迁移或批量改写历史订单；当前重试不再产生新渠道引用。同渠道恢复依赖插件的引用幂等，本地锁和历史引用隔离不构成全链路绝无重复支付的保证。

**依据：** [OrderCreationService](../app/Services/OrderCreationService.php)、[网关配置](../config/payment_gateway.php)、[API 控制器](../app/Http/Controllers/Api/OrderController.php)、[建单失败与幂等补建测试](../tests/Feature/OrderCreationFailoverTest.php)、[API 重试邮件测试](../tests/Feature/OrderCreateRetryMailTest.php)。

## D-25 渠道连续建单失败达到阈值自动停用

**状态：当前工作区实现，未提交（2026-11-03，按用户要求）。** 新增系统配置 `payment.max_failed_times`，默认 5；渠道 `max_failed_times` 可填写大于等于 1 的整数单独覆盖，留空由 `PaymentMethod::maxFailedTimes()` 动态继承当前系统值。`consecutive_failed_times` 按渠道 ID 持久化、跨商户累计，共享渠道不按商户、组或订单分桶。此处失败指建单或明确的商品准备失败，不是客户后续支付失败。

**原因与影响：** 在 [D-24](#d-24-网关建单失败立即返回与原渠道重试) 的失败立即返回和原渠道重试基础上，连续失败达到阈值时自动将渠道 `is_active` 置为 false，防止异常渠道继续接收新单；不自动切换渠道或重新启用。`OrderCreationService::createPayment()` 通过 `PaymentMethodFailureService::recordFailure()` 记录一次最终失败，内部 HTTP 连接重试无论执行几次只计一次，直接调用底层网关客户端的工具操作不在统计入口内。

有效 `pay_url` 成功落库后调用 `recordSuccess()` 清零；已有链接的幂等命中、金额/授权校验或风控拒绝既不计数也不清零。记录结果时在同一事务先锁原订单、再锁渠道，显式限定订单商户并核对当前 `payment_method_id` 与远端引用；失败结果还须确认无链接、未收款且仍为 `pending/failed`，避免迟到结果计入其他尝试。成功只清零，不自动恢复被停用渠道；渠道复制不复制累计次数。

2026-11-03 按用户后续要求补充：人工核验恢复后，通过 `PaymentMethod` 模型将渠道从停用改为启用时，同次保存自动清零连续失败次数，开始新一轮计数。模型 `updating` 事件仅在 `is_active` 变更为 true 时重置，普通编辑保持启用状态及新建不误清；运行计数继续 `saveQuietly()`，不受此事件影响。编辑页仅写入主动修改的启用状态，事务中锁定并读取最新渠道后保存，避免旧页面误启用自动停用渠道或重复清零新失败。该补充无需新增迁移，不批量清空其他渠道计数，也不改变自动停用后须人工核验恢复的规则。

仅在启用渠道达到阈值并转为停用时发送一次停用提醒，收件人为原订单商户与 `PaymentMethod::notifiableMerchantIds()` 返回的全部关联商户，合并去重；共享渠道包括所有分配商户。计数事务提交后复用 `TelegramNotificationService::send()`，未配置或未启用机器人、触发限流等情况沿用原服务的跳过规则，通知异常不覆盖建单异常。原有每次网关建单最终失败的 `PaymentLinkCreationFailed` / 队列提醒继续保留；商品准备失败新增计数，但不改变原事件范围。

新增迁移为渠道添加可空 unsigned integer `max_failed_times`（默认 NULL）及 unsigned integer `consecutive_failed_times`（默认 0），不回推历史失败、不更改订单快照或资金。迁移和专用 `PaymentFailureSettingsSeeder` 均以 `insertOrIgnore` 补缺系统配置，不覆盖管理员保存的值；`SystemConfigSeeder` 调用该专用 Seeder。部署需先执行新迁移，回滚只删除渠道新列并保留系统配置。后台风控区支持阈值覆盖及动态默认值提示，列表提供默认隐藏的只读连续失败次数列。

首次上线执行 `2026_11_03_223806_add_failure_limits_to_payment_methods_table` 即可同时补缺默认配置；若已执行，人工重新启用清零只部署代码。专用 Seeder 可以安全重跑，但不是迁移完成后的必需步骤；具体命令见 [数据库上线操作](database.md#2-租户身份和渠道)。

**依据：** [PaymentMethod](../app/Models/PaymentMethod.php)、[PaymentMethodFailureService](../app/Services/PaymentMethodFailureService.php)、[OrderCreationService](../app/Services/OrderCreationService.php)、[PaymentMethodResource](../app/Filament/Resources/PaymentMethodResource.php)、[新增迁移](../database/migrations/2026_11_03_223806_add_failure_limits_to_payment_methods_table.php)、[PaymentFailureSettingsSeeder](../database/seeders/PaymentFailureSettingsSeeder.php)、[SystemConfigSeeder](../database/seeders/SystemConfigSeeder.php)、[建单失败保护测试](../tests/Feature/PaymentMethodCreationFailureTest.php)、[阈值配置测试](../tests/Feature/PaymentFailureSettingsTest.php)。

## D-26 物流异常统一判定、可配置阈值与商户提醒

**状态：当前工作区实现，未提交（2026-11-04，按用户要求）。** 物流 Provider 的 `Exception`、`DeliveryFailure`、`Expired` 主状态统一设置 `logistics_anomaly=true`，用于列表红色、异常筛选和 Telegram 提醒；仍展示原状态的中文含义。当前 `Delivered` 清异常并保存签收时间，不推进订单业务状态为 `completed`。供应商异常优先于时间异常；其他状态按已有签收时间豁免、发货超期、事件陈旧依次判断。

**原因与影响：** 仅按时间标异会遗漏投递失败或运输过久；写死 5/15 天也不能适应不同物流时效。新增系统配置 `logistics_tracking.stale_days`（默认 5）与 `logistics_tracking.no_delivery_days`（默认 15），均为 1–3650 整数天，连续 24 小时算一天，满阈值即判异。没有事件时间时从发货时间计算陈旧；保存清缓存，非法旧值回退默认。保留历史原因码 `stale_5d` / `no_delivery_15d`，展示使用当前配置，提醒携带触发时的阈值快照。

每天 06:00 的扫描保留原订单快照和业务状态范围，补扫供应商异常及已有异常，支持调大阈值后清除时间异常；按缓存游标分批轮转。首次预测异常且驱动、订单快照、商户凭证均允许时先主动查一次，查询确认已签收或轨迹新鲜则不误报；查询失败仍按本地数据判定。已有异常不反复查询。

异常保存时锁定当前商户的物流行，正常→异常才在提交后派发 `LogisticsAnomalyDetected`；重复回调不重复排队，恢复正常后再次异常可再提醒。独立队列监听器复用 `TelegramNotificationService`，仅向订单所属商户 Bot 发送订单号、商户单号、运单号、状态和原因，不发送凭证或客户资料。动态内容截断并 HTML 转义；缺失/禁用 Bot 跳过，限流或 HTTP 失败每 60 秒重试，最长 24 小时，提醒故障不回滚物流。

更换运单号清除原 Provider 注册、承运商、主/子状态、轨迹事件、签收时间、查询时间、错误和异常字段；同号编辑保留。迟到的旧号注册或查询结果不得覆盖新号。未填写新发货时间时仍保留原发货时间，沿用原录入规则；本次不批量清洗历史物流，也不改变支付插件同步、商户网站物流回传或账务。

迁移与专用 Seeder 仅补缺失系统配置，不覆盖已保存值，回滚保留配置。部署需执行新配置迁移；已有运单由后续扫描或回调逐步应用新判定，不回填历史轨迹。

**依据：** [LogisticsTrackingSyncService](../app/Services/LogisticsTracking/LogisticsTrackingSyncService.php)、[异常扫描命令](../app/Console/Commands/ReflagLogisticsTrackingAnomaliesCommand.php)、[OrderShipping](../app/Models/OrderShipping.php)、[异常事件](../app/Events/LogisticsAnomalyDetected.php)、[提醒监听器](../app/Listeners/SendLogisticsAnomalyNotification.php)、[配置迁移](../database/migrations/2026_11_04_094301_add_logistics_anomaly_thresholds_to_system_configs.php)、[配置 Seeder](../database/seeders/LogisticsAnomalySettingsSeeder.php)、[异常判定测试](../tests/Feature/LogisticsAnomalyTrackingTest.php)、[配置测试](../tests/Feature/LogisticsAnomalySettingsTest.php)、[Telegram 测试](../tests/Feature/LogisticsAnomalyTelegramTest.php)。

## D-27 商户编辑页集中授权系统支付渠道

**状态：当前工作区实现，未提交（2026-11-04，按用户要求及后续澄清）。** 商户编辑页顶部提供“授权支付渠道”，弹窗左侧显示可授权系统渠道，右侧显示已有授权和本商户自有支付方式；点击左侧授权、点击右侧系统渠道取消授权并即时移动，操作逐项立即保存，关闭不撤销。本商户自有支付方式固定在右侧并标记“商户自有（不可取消）”，不提供变更按钮。支持名称/编码搜索、数量和停用状态展示，不提交商户主表单中的未保存资料或默认财务配置。

**原因与影响：** 从商户维度连续授权多个渠道，减少逐个打开支付方式编辑页的操作。启用的平台账号须同时具备商户管理与渠道管理权限；超管可选择所有商户，商户级管理员限自己名下商户。按用户最终澄清，系统渠道范围依据该商户已保存的所属管理员（`payment_methods.owner_id=merchants.owner_id`、`payment_methods.merchant_id IS NULL`、未软删除），不按当前登录管理员筛选；超管代管其他管理员商户时可以调整该商户所属管理员的系统渠道。所属管理员为空的平台直管商户延续当前超管名下渠道的行为，不合并其他超管渠道或无归属渠道。商户自有渠道（`payment_methods.merchant_id=当前商户 ID`）无需 Pivot 授权，直接显示在右侧，服务端也拒绝通过本入口增删其授权。范围外系统渠道及其他商户自有渠道隐藏，已有隐藏分配保留。直接调用授权/撤销也须符合相同渠道归属条件，不改变支付方式 Resource 原有的管理权限。停用与授权独立，停用渠道仍可配置授权，使用时继续受既有启用检查限制。

`MerchantPaymentMethodAssignmentService` 每次重读操作者、权限与归属，在事务内先锁商户、再锁渠道，以显式授权/撤销实现幂等，不整表覆盖分配关系。授权复用 `MerchantPaymentMethod` 的默认财务初始化，沿用 [D-22](#d-22-商户默认财务配置按分配复制)。按用户后续要求，在此弹窗取消授权时同步删除该商户与渠道的 `merchant_payment_settings`，替代此前取消后保留配置的规则；重新授权复制商户当时已保存的默认配置。撤销同时移除当前商户支付组（含软删除组）内该渠道引用，保留其他商户及该商户其他渠道的配置、分配和支付组；重新授权不自动恢复旧组引用。即使分配已不存在，重复撤销也会清除该商户与渠道的残留配置。本次仅修改商户弹窗的明确撤销路径，不批量清理历史残留，不改变旧支付方式编辑页或直接 Pivot 解除关系的配置保留行为，避免系统渠道转为商户自有时误删仍有效的费率。页面同步刷新独立财务配置表，不调用支付网关，不改变渠道归属、历史订单或账务，无需新增迁移。

**依据：** [EditMerchant](../app/Filament/Resources/MerchantResource/Pages/EditMerchant.php)、[MerchantPaymentMethodAssignmentService](../app/Services/MerchantPaymentMethodAssignmentService.php)、[弹窗模板](../resources/views/filament/resources/merchant-resource/payment-method-authorization.blade.php)、[授权回归测试](../tests/Feature/Filament/MerchantPaymentMethodAssignmentTest.php)。

## D-28 支付组内支付方式常驻双栏管理

**状态：当前工作区实现，未提交（2026-11-04，按用户要求及后续补充）。** 支付组创建和编辑页共用未加入/已加入双栏面板，名称/编码搜索、数量、停用状态以及右侧进单权重输入均在页面内操作。渠道信息、固定宽度权重和移出图标按行对齐，编辑时只在权重改变后失焦/回车保存，去掉逐行保存按钮；校验错误显示在对应行。创建页先选择商户再加载渠道，未选择时显示引导，成员和权重随创建一起保存。编辑页点击加入或移出即时保存，原当天成交、笔数、负载率和候选序表格保留；软删除的有效组内成员显示在右侧供移除，不允许加入已删除渠道或修改其权重。

**原因与影响：** 减少逐次打开添加/编辑弹窗的步骤，同时保留组内路由权重。候选按支付组当前所属商户的 `PaymentMethod::forMerchant()` 读取，包含商户自有及已分配系统渠道，与商户授权弹窗按渠道归属管理员筛选的用途不同。普通商户账号也可操作自己的支付组，须有支付组管理权限。Service 和独立关系组件每次重查操作者启用状态、权限、组/商户归属；写入先锁商户、再锁组及渠道，防止与商户撤销授权并发时插回失效关系。

操作仅修改 `payment_group_methods`，不解除商户授权、不删除财务配置、不改其他组；新选择默认权重 100，重复加入保留已有权重。权重为 1–2,147,483,647 整数，编辑保存后同时刷新表和路由负载快照缓存。停用渠道可配置组关系，实际下单继续受既有启用和风控规则约束，不改变前三候选随机等路由策略。

创建页通过非 relationship 的 `ViewField('paymentMethods')` 暂存 `[{id,priority}]`。选择商户后才返回该商户可用的安全字段；切换商户由服务端清空暂存选择与权重，并按商户重建面板，加载期间禁用旧面板。`CreatePaymentGroup` 提交时提取暂存列表，`PaymentGroupMethodService::createGroup()` 锁商户并重读操作者权限/归属，校验列表键、唯一渠道 ID、权重及每个渠道的当前可用性，再原子创建支付组和带权重的关系。已撤销授权、软删除、越权商户或非法权重均拒绝，不留下支付组；空选择可创建。此流程替代初版创建页多选下拉，创建之前不写组或 Pivot。

编辑页彻底移除主表单的 `paymentMethods` 关系字段，避免主表单保存覆盖即时成员变更，或把权重重置为旧 `pivotData` 中的 100。按用户后续明确要求，**支付组创建后不能转移到其他商户，超级管理员也无例外**：编辑页禁用所属商户选择；保存时锁定当前商户和组，若提交商户与数据库归属不同则报字段验证错误，整次保存不写入，也不清理组成员。`PaymentGroup::updating` 还会拒绝模型实例的 `merchant_id` 变更，保护其他 Eloquent 实例更新路径；创建和同商户普通编辑正常。此要求替代中间实现中的“转移商户后清理旧成员”，移除转移逻辑和初始商户追踪。面板始终使用数据库已保存的所属商户，普通保存后仍派事件刷新关系组件。无需新增迁移或数据库触发器，不通过本次改动批量修正历史数据或重置存量权重。

**依据：** [PaymentGroupResource](../app/Filament/Resources/PaymentGroupResource.php)、[CreatePaymentGroup](../app/Filament/Resources/PaymentGroupResource/Pages/CreatePaymentGroup.php)、[EditPaymentGroup](../app/Filament/Resources/PaymentGroupResource/Pages/EditPaymentGroup.php)、[PaymentGroup](../app/Models/PaymentGroup.php)、[PaymentMethodsRelationManager](../app/Filament/Resources/PaymentGroupResource/RelationManagers/PaymentMethodsRelationManager.php)、[PaymentGroupMethodService](../app/Services/PaymentGroupMethodService.php)、[共用列表](../resources/views/filament/resources/payment-group-resource/method-panel-lists.blade.php)、[行为测试](../tests/Feature/Filament/PaymentGroupMethodManagementTest.php)。

## D-29 订单主状态由结算与售后事实统一推导

**状态：当前工作区实现，未提交（2026-11-06，按用户要求）。** 补充 D-07、D-17，替代 D-18 中“主动查询 `paid` 可无条件覆盖订单主状态”的行为及 D-08 中人工审核期间吞掉最终网关退款/拒付的处理。`completed` 表示本单正常结算阶段完成，仍可进入网关争议、人工审核、退款或拒付；不是售后终态。

**原因与影响：** 旧完成状态只能由人工标签产生，实际结算未同步主状态；同时完成保护吞掉真实售后回调。支付、履约、结算和售后使用同一状态字段的多个直接写入入口，还会在查询/审核关闭后错误回到待发货。现在由 `OrderLifecycleService` 复用真实结算、有效物流、退款/拒付流水、活动审核和网关事实统一推导。

- 支付后起算（数据库仍为 `immediate`）确认支付后进入 `pending_settlement`，实际结算成功后进入 `completed`；发货后起算（`shipped`）先 `paid`，首次有效物流启动周期后 `shipped`，真实结算后 `completed`。0 天周期可同次完成，不强制停留在中间状态。
- 起算和金额规则不变：支付按首次 `paid_at`；物流按首次有效录入与支付时间中较晚者，改号/补发不重启；N×24 小时；结算只划转剩余净额，循环保证金期限从实际结算起算。部分退款保留售后主状态，结算 `cancelled` 不能等同订单已全退。
- 已完成也接收网关 `disputing/refunded/confused`，后者映射 `chargeback`；保存可靠反向待办再尝试记账。重复事件、账务唯一键、退款累计和已拒付本金流水共同防重；部分退款后拒付仍需人工核对，不重复扣本金。旧单新收到反向结果可以补偿该反向记账，不重做历史收入或结算。
- 人工审核开立支持合理已收款阶段，保留权限、租户范围和 TOTP；关闭只处理该事件冻结并重新推导主状态，不固定回 `paid`。活动审核、网关争议和未结清反向待办直接阻止结算或保证金释放，不依赖主状态标签；暂停保留原周期。
- 网关事实独立保存在 `gateway_status/gateway_status_updated_at/gateway_status_version`，接收结果写 `order_gateway_status_events`，只保留标准化白名单摘要。保留当前网关尝试引用校验，对旧事件、同时间冲突及查询期间新回调做保护。`conflict/query_race` 保留审计和警告，由后台“查询最新状态”核对，不自动批量重查。查询 `paid` 不抹掉实际退款/拒付、活动审核或已完成结算；不重复首次入账、商户通知、广告转化或指定渠道付款提醒。
- 首次通知及广告转化意图与首次支付事实同事务保存、提交后派发，持久化失败则回滚以支持回调重试；首次支付通知继续固定 `status=paid`。后台新增待结算、真实结算展示/筛选，人工直接“设为完成”取消。API 旧 `status` 将待结算映射为 `paid`，新增 `order_status/settlement_status/shipping_status`；观察者待结算归支付成功，风控/统计仍取曾支付成功和 `paid_at`。

**历史兼容与上线：** 迁移仅新增字段及审计表，不批量改状态、回填事件、全量查询网关或重记账。`settlement_mode=NULL` 的历史订单保留原资金规则，旧人工完成阶段在后续事件中通过 `normal_status` 保存，以便争议往返恢复。已有结算快照的存量订单随新事件和到期处理收敛；历史忽略的售后需单独核对。部署先执行新增迁移，再使用新代码并重启队列进程；已有接收审计后禁止破坏性回滚，使用前向修复。

**依据：** [OrderLifecycleService](../app/Services/OrderLifecycleService.php)、[OrderPaymentStatusService](../app/Services/OrderPaymentStatusService.php)、[BalanceService](../app/Services/BalanceService.php)、[OrderAccountingService](../app/Services/OrderAccountingService.php)、[网关状态审计迁移](../database/migrations/2026_11_06_102253_add_gateway_status_tracking_to_orders.php)、[API 控制器](../app/Http/Controllers/Api/OrderController.php)、[生命周期测试](../tests/Feature/OrderLifecycleTest.php)、[结算测试](../tests/Feature/OrderSettlementBalanceTest.php)、[API 兼容测试](../tests/Feature/OrderStatusApiCompatibilityTest.php)、[后台动作测试](../tests/Feature/Filament/OrderLifecycleActionsTest.php)。

## D-30 净入账保留两位小数并舍去千分位

**状态：当前工作区实现（2026-11-06，按用户要求）。** 净额不向下取整到整数美元。百分比手续费先按更高精度计算，再保留两位小数，第三位及之后直接舍去，不四舍五入；固定手续费本身已是两位小数。净额为 USD 成交额减去这两项手续费，同样只保留两位小数。手续费仍超过成交额时拒单；净额为正的美分金额可以下单。历史订单快照不重算。

**依据：** [Money](../app/Support/Money.php)、[OrderCreationService](../app/Services/OrderCreationService.php)、[精度测试](../tests/Feature/SettlementAmountPrecisionTest.php)。

## D-31 crypto-payment-amount-mutex

**状态：已撤销（2026-11-10，当前工作区，按用户要求移除并重新设计）。** 原固定地址、金额互斥和 Tatum 监听方案已移除；对应迁移回滚后删除，订单折算金额恢复为 `decimal(15,2)`。新工作流尚未确定，不沿用原方案约束。原实现可查 Git 历史。


## D-32 支付方式管理员边界与点选授权

**状态：当前工作区实现，未提交（2026-11-11，按用户要求）。** 商户级管理员只能查看自己归属的系统级渠道及自己名下商户自有渠道，不因分配关系获得其他管理员渠道的可见性。普通商户使用渠道仍遵循自有及已授权范围，`forMerchant()` 的下单语义保持；列表财务筛选额外检查管理员归属。

**原因与影响：** 支付方式禁止跨商户级管理员新增分配，超级管理员代操作也按渠道与商户的归属判断。Pivot 写入及双向页面入口都校验。平台直管商户（无 `owner_id`）只接受超管归属或历史无归属系统渠道，不能使用普通商户级管理员渠道进行新分配。历史跨管理员授权关系保留，不批量修改既有渠道使用、支付组、订单和账务；管理员不再从历史授权关系看到对方渠道。

支付方式编辑页移除管理员转交动作并锁定已有商户归属，顶部显示管理员标签；新增最多 5,000 字符的可空备注。原 `PaymentMethodOwnershipService` 保留为无页面入口的内部方法，拒绝会使现有分配跨管理员的转交。按用户后续调整，创建、编辑页授权放在手续费配置下方的独立常驻双栏面板，不使用弹窗；仅未设置所属商户的系统级支付方式显示，商户自有支付方式隐藏。按名称/ID 搜索并立即保存，主表单不提交已有分配字段。新授权复制商户最新已保存的手续费、保证金和结算默认值，已有独立值不覆盖；明确撤销清理分配、该商户含软删除支付组引用及独立财务配置，重新授权重新初始化。此规则替代 [D-22](#d-22-商户默认财务配置按分配复制) 和 [D-27](#d-27-商户编辑页集中授权系统支付渠道) 中“原支付方式编辑页取消保留配置”的旧约定；直接 Pivot 解除关系仍保留配置。

**依据：** [PaymentMethod](../app/Models/PaymentMethod.php)、[MerchantPaymentMethod](../app/Models/MerchantPaymentMethod.php)、[PaymentMethodResource](../app/Filament/Resources/PaymentMethodResource.php)、[PaymentMethodMerchantAssignmentService](../app/Services/PaymentMethodMerchantAssignmentService.php)、[管理员边界测试](../tests/Feature/PaymentMethodAdministratorBoundaryTest.php)。

## D-33 新版区块链基础分阶段接入与精确账务

**状态：当前工作区初始实现，未提交、未开放收款（2026-11-11）。** 用户已从方案讨论进入代码开发。当前仅实现支付类型、永久绑定和独立账务基础，保留法币路由与原订单流程；链上渠道暂不能通过原网关建单。旧 [D-31](#d-31-crypto-payment-amount-mutex) 的金额互斥匹配不恢复。

**口径：** BTC／LTC／ETH／两个网络 USDT 的数量以最小单位整数字符串保存，应用层用 bcmath 校验 uint256 范围并精确计算。v1 报价上浮作用于应付数量并全部归商户；百分比服务费加同价 USD 固定费折币合计后向上取整，形成净额，费用不得耗尽实收。法币两位小数及 D-30 不受影响。比例取值初始限制为服务费 `[0,100)`、报价上浮 `[0,100]`；后续若改舍入规则必须使用新版本，不能回算旧单。

自动账务入口初始仅接受已经由内部核验器确认的事件，累计数量恰好等于应付、事件在请求创建至报价到期内上链且不早于起扫高度；到期后才达到确认条件仍可按冻结报价入账。少付、多付、迟到及重组差异由后续明确处理流程接管，不能用放宽金额或时间匹配来让测试通过。

**边界与影响：** 本条记录第一批基础实现；后续后台配置和只读派生进展见 [D-34](#d-34-链上配置隔离与只读公钥地址分配)。请求仍先为 `preparing`，Tatum 核验器、显式重新报价、可靠记账待办、订单事实／通知编排、取款及本地工具未完成。`CryptoBalanceService` 目前仅记独立账务，不代表完整支付确认服务；未经后续集成不得对外开放。现有法币资金入口对链上订单做服务端拒绝。地址和金融审计不会通过软删除释放唯一性，具体表与 MySQL 待验证项见[数据库第 8 节](database.md#8-新版区块链收款基础当前工作区待集成)。

**依据：** [CryptoQuoteService](../app/Services/CryptoQuoteService.php)、[CryptoPaymentRequestService](../app/Services/CryptoPaymentRequestService.php)、[CryptoBalanceService](../app/Services/CryptoBalanceService.php)、[报价测试](../tests/Feature/CryptoQuoteTest.php)、[基础测试](../tests/Feature/CryptoPaymentFoundationTest.php)。

## D-34 链上配置隔离与只读公钥地址分配

**状态：当前工作区初始实现，未提交、未真实链联调（2026-11-11）。** 按继续开发授权完成后台类型 Radio、文本／下拉模板、配置校验、五类模板补缺、Tatum 行情及公钥地址只读客户端。现有渠道／模板禁止切换类型；链上配置不要求 WordPress 凭证，也不能被自动或手动同步到插件；商户与管理员仍沿用现有管理归属和授权范围。

当前采用 Tatum v3 的公钥地址接口，不增加签名依赖，不调用钱包生成、私钥派生、签名或广播接口。只接收 Base58Check `xpub/tpub`、固定兼容方案及本地独立恢复的索引 0／1 地址；这会向 Tatum 提交扩展公钥，后台已明示。尚未完成实际钱包路径和本地工具的恢复实测，接口响应与两个地址比对不等于本地独立验证了全部派生或网络监听能力。只能使用专用未占用分支；地址历史检查仍是发布付款要求前的必要工作。

技术派生源按链族＋chain code／公钥身份全局去重，多个渠道／商户使用同源时共用计数器，不按钱包归属模式拆账。先在事务中预留索引，再在事务外请求；正常新分配先查恢复索引 0／1，再查目标索引，订单从 2 开始。失败不释放索引，同一分配键重试复用；分配、地址绑定和报价仍独立留审计记录。恢复校验与目标派生的 Credits 均需计入成本，不能沿用只有收款订阅的旧估算。

行情只请求受支持资产的 USD 价格，保留十进制字符串及供应商毫秒时间；按资产共享最多十秒缓存，读缓存时再检查新鲜度，禁止故障时退回旧报价。凭证按正式／测试网络分别从配置环境变量取得，HTTP 超时与安全 GET 重试有界，不跟随重定向；错误不携带凭证、完整公钥 URL 或原始异常链。正式 USDT 合约固定，测试代币需平台白名单，不从名称推断资产身份。

本条记录第二批内部地址／报价准备，未创建真实订阅、不公开可支付页面、不进入法币账务。第三批订阅及 HMAC 的实现边界见 [D-35](#d-35-订阅代次回调线索与保守退订)；链上核验／确认／补查、可靠待办和订单通知仍是后续开放前的必需工作。详见[方案第 3.5 节](blockchain-payment-plan.md#35-当前代码采用的配置与只读派生方式)。

**依据：** [配置校验](../app/Services/CryptoPaymentConfigurationService.php)、[只读客户端](../app/Services/TatumReadClient.php)、[行情缓存](../app/Services/CryptoMarketPriceService.php)、[地址分配](../app/Services/CryptoAddressAllocationService.php)、[后台测试](../tests/Feature/Filament/CryptoPaymentMethodTest.php)、[分配测试](../tests/Feature/CryptoAddressAllocationTest.php)。

## D-35 订阅代次、回调线索与保守退订

**状态：当前工作区第三批实现，未提交、未真实 Tatum 联调（2026-11-11）。** 订阅按请求与代次保存不可变身份，回调 UUID 不跨代次复用；创建前查回、超时再查回，避免盲目重复 POST。远端操作在金融事务外完成，按代次加锁并保留失败与重试状态；API Key 指纹变化阻止继续操作原订阅。

HMAC 校验仅证明回调来源，不能证明交易最终到账。回调先校验请求大小、环境密钥、原始 body 签名及限定的 JSON 空白兼容签名，再按本地监控绑定商户，保存摘要与白名单线索到可靠收件箱；不信任回调商户 ID，不保存任意原始数据，不直接写余额或订单已支付事实。已取消／旧代次的有效线索仍接受。第三批未实现核验与消费；第四批以太坊进展见 [D-36](#d-36-以太坊规范链核验与冻结确认策略)，其他链和订单通知编排仍待实现。

**本段退订接管条件已由 [D-65](#d-65-退订后两轮地址补查替代持续扫块) 替代，保留为第三批历史。** 即时订阅到期不等于可以安全退订：必须有新鲜连续历史扫描及补查覆盖证明，并无待核验收件或未确认事件。缺少任一条件就保留订阅，避免为节约 Credits 失去迟到款发现能力。重新订阅只在有效报价、恢复次数和补查条件满足时创建新代次。第三批当时无真实历史扫描器，不能手工伪造检查点来启用收款或退订。后台必要参数当时以补缺 Seeder 留空／关闭初始化；该初值策略已由 [D-42](#d-42-区块链系统配置随部署迁移初始化) 替代，收款开关仍关闭。HMAC 配置使用专用 API Key 并与其已有回调约定一致。

**依据：** [监控服务](../app/Services/CryptoAddressMonitorService.php)、[订阅客户端](../app/Services/TatumSubscriptionClient.php)、[验签](../app/Support/TatumWebhookSignature.php)、[回调收件](../app/Services/TatumWebhookService.php)、[维护命令](../app/Console/Commands/MaintainCryptoSubscriptions.php)、[监控测试](../tests/Feature/CryptoAddressMonitorTest.php)、[回调测试](../tests/Feature/TatumWebhookTest.php)、[配置测试](../tests/Feature/CryptoSettingsTest.php)。具体设置与未完成项见[方案第 6.8 节](blockchain-payment-plan.md#68-当前第三批代码的落地边界未开放收款)。

## D-36 以太坊规范链核验与冻结确认策略

**状态：当前工作区第四批实现，未提交，仅模拟 RPC 验证（2026-11-11）。** 核验层先实现 ETH 顶层直接转入及 USDT ERC20，限定 Tatum 正式／Sepolia 固定网关和只读方法。核对 chain ID、交易／收据／规范区块及交易位置，数量由十六进制精确转整数字符串，ERC20 必须匹配冻结合约与正确的 Transfer 事件。ETH 内部转账及 BTC／LTC／TRC20 不由本适配器处理。

确认策略采用 `evm-finalized-v1`：显式配置最小确认数并要求 finalized 覆盖，最后再次查询规范区块防止读取过程中发生切链。请求创建时冻结配置和确认阈值，之后不读取新配置替换旧单标准；缺快照或 finalized 信息就拒绝确认。该策略的等待时长与套餐 RPC 能力仍需真实测试，不默认生产安全阈值。

收件消费独立开关、默认关闭，按请求加锁；HTTP 在事务外，事件和收件状态在商户 → 订单 → 请求 → 收件 → 事件锁序中一起落库。重复线索不重复生成经济事件，错误退避重试；已最终确认的收件不无限轮询。后续冷扫描还须负责持续规范链审计，不能用本阶段的按线索核验代替历史发现或完整重组监控。

规范区块变化会将旧事件标孤块并撤销确认，同身份同金额重新入块可恢复。金额冲突、迟到款及已记账后的规范链异常转审核，不重写金额、不自动反向调整余额；收件核验也不直接更新订单或调用账务。独立入账与安全退订均检查 pending／confirming／review 收件，入账额外拒绝未确认有效事件。完整付款状态／通知和可靠记账编排仍待实现。

**依据：** [只读 RPC 客户端](../app/Services/TatumEvmRpcClient.php)、[以太坊核验](../app/Services/CryptoEvmVerificationService.php)、[消费服务](../app/Services/CryptoReceiptVerificationService.php)、[维护命令](../app/Console/Commands/VerifyCryptoReceipts.php)、[核验测试](../tests/Feature/CryptoEvmVerificationTest.php)、[客户端测试](../tests/Feature/TatumEvmRpcClientTest.php)、[方案第 6.9 节](blockchain-payment-plan.md#69-当前以太坊核验与收件消费第四批未开放收款)。

## D-37 UTXO 原始数量与 TRON 固化收据

**状态：当前工作区第五批实现，未提交，仅模拟接口验证（2026-11-11）。** 延续 [D-36](#d-36-以太坊规范链核验与冻结确认策略) 的只读、冻结策略和收件消费边界，增加 `utxo-canonical-v1` 与 `tron-solidified-v1`。按正式／测试网络冻结确认数和创世区块哈希，默认无值，不在消费时读取当前配置替代旧单网络标准。新适配器只生成核验事件，不发布付款要求或自动记账。

BTC／LTC 金额从原始交易 uint64 字节读取，不从 JSON 小数转换；重算非 witness txid，并核对脚本、规范区块和交易成员，末尾再读链高／区块。输入来源另行核对，订单地址参与花出时，返回该地址的找零不作为新收入。每次最多检查十六个不同历史输入交易；缺少归档权限、coinbase、MWEB、未知序列化和超出预算均保持待核验，不放宽金额或跳过检查。混合自有与外部输入的联合交易不自动拆净额。

TRC20 核对 Base58Check、冻结 USDT 合约、raw_data_hex 对应交易 ID、规范区块／时间／成员及交易执行状态；仅解析合约 Transfer 日志。FullNode 可查不等于最终确认，必须满足冻结深度及 Solidity 同区块／同收据的固化证据，再复查规范块。原生 TRX 燃料补充、其他合约、转出、自转和零额不计入 USDT。

事件继续复用现有唯一约束：UTXO 位置 `vout:{索引}`，TRC20 位置 `log:{交易内索引}`。共享消费锁期限十分钟，覆盖有界历史输入请求；HTTP 仍在金融事务外，失败记录固定错误码并退避。没有新增迁移、依赖或真实钱包数据。历史发现、持续规范链审计、ETH 内部转入和完整付款／账务编排仍待实现；测试通过不等于正式／测试网络已联调。

**依据：** [链查询客户端](../app/Services/TatumChainReadClient.php)、[UTXO 核验](../app/Services/CryptoUtxoVerificationService.php)、[原始解码](../app/Support/CryptoUtxoTransaction.php)、[TRON 核验](../app/Services/CryptoTronVerificationService.php)、[地址校验](../app/Support/CryptoTronAddress.php)、[策略快照](../app/Support/CryptoVerificationPolicy.php)、[UTXO 测试](../tests/Feature/CryptoUtxoVerificationTest.php)、[TRON 测试](../tests/Feature/CryptoTronVerificationTest.php)、[客户端测试](../tests/Feature/TatumChainReadClientTest.php)、[方案第 6.10 节](blockchain-payment-plan.md#610-btcltc-与-trc20-核验第五批未开放收款)。


## D-38 连续历史覆盖与订单原子入账

**状态：当前工作区第六批实现，未提交，仅隔离库与模拟接口验证（2026-11-11）。** 承接 D-35 至 D-37 的可靠线索和核验边界。**持续扫描与退订接管部分于 2026-11-18 被 [D-65](#d-65-退订后两轮地址补查替代持续扫块) 替代；以下为当时实现。** 新历史服务读取完整区块，按网络／下一高度合并最多 500 个请求的发现查询，继续扫描过期、冷地址及已入账请求；不依赖付款页打开、不复用旧地址、不按金额配单。请求游标和发现线索一起提交；只有追平新鲜链头才形成退订接管证明。新增扫描和自动记账开关均默认关闭。

ETH 通过 Geth callTracer 补齐内部价值转移；整块 trace 必须完整，核验另取交易 trace 并匹配顶层交易。撤销子树、委托调用的表面 value、自转和零值不作为收入。调用路径用作事件位置，顶层仍为 native:0，避免重复叠加。冻结 internal_transfers 能力，旧报价不静默重写。节点返回不是独立多源共识，真实 trace／历史权限必须单独验收。

扫描保存最近 64 个规范块哈希，发现分叉回到共同祖先并重新扫描、撤销孤块确认；超出窗口从起扫点补查并转审核。已记账异常保留原流水和付款事实，不自动伪造法币退款或冲正；人工处理界面仍待实现。链上 UTC 时间在数据库落库前转系统时区，避免无时区字段解释偏移。

`CryptoSettlementService` 在历史新鲜、无待核验或审核、所有有效事件均确认且总额恰好付足时，以商户 → 订单 → 请求锁序，在一个事务内提交净额加密余额、唯一收入流水、请求入账标记、订单付款事实与首次商户通知记录。调用现有 `OrderPaymentStatusService` 的独立链上入口，复用通知及事件；原法币网关拒绝链上订单。消息提交后发送，滞留的同一通知记录可重投；通知协议仍要求幂等。部分付款等待，异常款保留审核原因，资金不跨网络／资产或混入法币。

**限制：** 逐请求游标合并仍有长期数据库写入和分批重复查询成本，全年地址规模吞吐未验证；生产前需要容量／Credits 验收，必要时再演进全网络共享游标。统一建单、付款页、重开报价、取款和本地归集不由本批完成。

**依据：** [历史读取](../app/Services/CryptoBlockHistoryService.php)、[扫描服务](../app/Services/CryptoHistoryScanService.php)、[记账编排](../app/Services/CryptoSettlementService.php)、[状态入口](../app/Services/OrderPaymentStatusService.php)、[新增迁移](../database/migrations/2026_11_11_221155_add_history_tracking_to_crypto_payment_requests.php)、[历史测试](../tests/Feature/CryptoHistoryScanTest.php)、[入账测试](../tests/Feature/CryptoSettlementTest.php)、[方案第 6.11 节](blockchain-payment-plan.md#611-历史补查冷地址与自动入账第六批未开放收款)。


## D-39 统一链上下单与可恢复付款页

**状态：当前工作区第七批实现，未提交，默认关闭（2026-11-11）。** 承接 D-33 至 D-38 的附表、独立币账、连续补查与订阅代次规则。用户授权先完成统一下单、付款页、重开补查与显式重新报价；统计、取款与本地归集不在本批范围。

三个建单入口继续复用 `OrderCreationService`，以锁定渠道的 `payment_type` 分流。链上原币法币金额保留，USD 参考值与 `exchange_rate` 用原始汇率，汇损为零；法币手续费／净额写零且不创建法币结算。约定服务费在 `crypto_checkouts.fee_snapshot` 冻结，重新报价只更新币价、数量上浮和有效期，不追随渠道后来修改的费率。指定链上渠道用订单应用 website 校验回跳域名，仍要求渠道授权；法币指定渠道规则不变。

付款链接指向本地页面。GET 只读；受 CSRF、Host、token/IP 限流保护的 POST 保存准备意图，后台有界任务执行外部请求。完整旧历史 → 查回或恢复订阅 → 交接窗口再次补查 → 再核对远端健康和全部订单请求 → 发布地址。持续使用已健康的订阅不会延长期限。缺口或异常时保持不可付款，公开响应不带配置、公钥源或凭证。

过期不会因访问自动换价，须显式 requote 且附当前请求 ID；同一旧报价只允许一个直接后继。创建后继与更新当前指针同事务，派生预留可重试，旧地址永久保留。旧请求出现款项或未核验线索时阻止继续发布；不同请求不合并金额，正常收入依旧订单唯一。webhook 落地同样锁商户→订单→请求，避免绕过报价和记账并发边界。

实现选择：每单最多 50 个报价请求、普通重开 30 秒冷却、准备任务每十秒默认最多 5 个订单；这些是当前代码的资源边界，不是已验证的长期容量承诺。真实测试链、生产 MySQL、浏览器操作及长期吞吐仍待验证；`CRYPTO_CHECKOUT_ENABLED` 默认 false。

**依据：** [统一建单](../app/Services/OrderCreationService.php)、[后台准备](../app/Services/CryptoCheckoutService.php)、[公开展示](../app/Services/CryptoCheckoutPresenter.php)、[保护条件](../app/Support/CryptoCheckoutGuard.php)、[附表迁移](../database/migrations/2026_11_11_232321_create_crypto_checkouts_table.php)、[付款页测试](../tests/Feature/CryptoCheckoutTest.php)、[下单测试](../tests/Feature/CryptoOrderCreationTest.php)、[方案第 6.12 节](blockchain-payment-plan.md#612-统一下单付款页与重开第七批默认关闭)。


## D-40 独立资产后台与人工取款核销

**状态：当前工作区第八批实现，未提交（2026-11-12）。** 按用户授权增加后台独立余额、链上交易明细和取款审核；取款只做审核与账务确认，不实际出款。

保留 `crypto_ledger_entries.order_id` 收入唯一约束，另建取款申请与不可修改的 `crypto_withdrawal_events`。两类凭证共同记录资产总额变化及占用，不为复用流水表而放松订单幂等约束。全部币账变动仍由 `CryptoBalanceService` 完成，先锁商户，余额／状态／审计同事务。

申请人确定本金及同资产费用上限；审批保留占用，人工核销扣本金与实际费用并释放余款，不把其他资产 Gas 自动折算扣费。申请人可撤销待审单；平台可驳回，已通过审核的申请须确认未在线下支付后才能释放。重复终态处理不再扣账；已入账交易出现复核或链证据异常时暂停该资产支出，保留释放占用的途径。

新的查看、申请、审核、核销权限独立；审核和核销还要求平台身份及当前商户归属。列表与提交均做隔离，资金操作重新读取账号／权限并复用 TOTP。使用独立账页展示精确币数量，订单列表和聚合统计的币种口径仍待单独调整。

本期仅提供明确标注的人工账务核销，线下凭证没有自动链上真实性验证，不把 completed 解释为系统已出款。实际链交易核验、批量输出分配、异常收入处理与反向纠错不由本批实现。迁移已在隔离 SQLite 测试中执行，生产 MySQL 迁移／并发及真实浏览器仍待验证。

**依据：** [账务入口](../app/Services/CryptoBalanceService.php)、[当前权限与租户过滤](../app/Support/CryptoFinanceAccess.php)、[取款 Resource](../app/Filament/Resources/CryptoWithdrawalResource.php)、[迁移](../database/migrations/2026_11_11_234945_create_crypto_withdrawals_and_events_tables.php)、[资金测试](../tests/Feature/CryptoWithdrawalTest.php)、[后台测试](../tests/Feature/Filament/CryptoFinanceTest.php)、[方案第 9.3 节](blockchain-payment-plan.md#93-当前后台实现第八批仅人工账务核销)。


## D-41 区块链订单展示、人工对账与重组欠额

**状态：当前工作区第九批实现，未提交（2026-11-12）。** 用户授权补齐订单列表／汇率／服务费汇总与异常款处理；钱包自动化归集推迟到交易测试完成后。

链上订单保留原法币金额及原始汇率 USD 参考成交额，净入账与服务费使用币数量，网络／资产分别汇总。继续保留 `paidEver()` 的历史口径；冲正不抹去曾支付事实。订单当前币账优先取最近人工接受／调整记录，不把原收入和累计处理后金额重复累加。当前汇总是筛选订单的现值口径，不是已完成的月度服务费账单系统。

保留订单收入唯一、不可修改的原凭证，另追加 `crypto_payment_resolutions`。人工处理按整单收集全部历史付款地址，只接受完整且新鲜的历史证明和已核验、已确认的规范链事件。相同网络／资产可以经平台人工确认汇总；这是对 D-39“不同报价不合并”自动处理边界的显式人工例外，不改变自动入账门槛。非零实收可人工接受少付、迟到、多付；过期订单需同样的明确确认，取消等其他终态不自动恢复。

实现选择：以首次收入的冻结报价计算累计实际收款的百分比费和一次固定费；无原收入时选最早有款报价。人工处理费用不超过实收，全部冲正时一并撤销服务费；确认窗口展示处理后的费用及净额。该选择针对异常处理，不改变普通报价净额必须为正的规则。它是本批实现口径，不代表用户已另外确认费用减免政策。

重组冲正先用未占用余额，不够时形成独立欠额；保持取款占用的授权事实，禁止继续支出。新收入、取消／驳回释放的余额优先抵扣，全部保存欠额前后值。不用失败并留旧余额的方式跳过已取款后的损失，也不擅自把已批准取款标为未出款。

`crypto_payments.reconcile` 与查看权限独立，还要求平台身份、当前商户归属、TOTP、原因及明示确认；商户仅查看。补查操作同样留审计，并保持待复核直至人工确认。不在服务器签名、归集、发退款或验证线下出款；已完成取款的凭证纠错仍与本次收入调整分开。

**依据：** [账务服务](../app/Services/CryptoBalanceService.php)、[证据核对](../app/Services/CryptoPaymentEvidenceService.php)、[后台入口](../app/Filament/Resources/CryptoPaymentReviewResource.php)、[展示](../app/Filament/Support/CryptoOrderDisplay.php)、[汇总](../app/Services/CryptoOrderStatisticsService.php)、[迁移](../database/migrations/2026_11_12_001838_add_crypto_payment_reconciliation.php)、[资金回归测试](../tests/Feature/CryptoPaymentReconciliationTest.php)、[后台与统计测试](../tests/Feature/Filament/CryptoOrderPresentationTest.php)。

## D-42 区块链系统配置随部署迁移初始化

**状态：当前工作区实现，未提交（2026-11-12）。** 用户要求上线执行迁移时一并初始化后台区块链配置，避免仅依赖手工运行 Seeder。本条替代 D-35 的空初值初始化方式，不改变订阅、补查、核验和入账条件。

实现采用七项初始化基线：报价上浮 `0`、报价有效期 `3600` 秒、行情最大年龄 `300` 秒、订阅时长 `7200` 秒、订阅覆盖 `{}`、恢复订阅上限 `3`、扫描健康门槛 `300` 秒。上浮默认 0，不把此前举例的 2% 自动作为商户报价政策；这些数值是本次实现选择，正式启用前仍需按业务和链验证。

新迁移冻结本次初值，通过查询构造器在事务内补缺，并仅填充旧记录的 `NULL`／空字符串，保留非空值、`0` 和已有元数据；提交后逐项清配置缓存。Seeder 使用一致初值，只补缺。迁移不调用可变 Seeder、不持有钱包密钥或 Tatum 凭证、不启用收款，也不重算历史报价和账本。回滚保留配置，避免误删迁移前已有或管理员之后修改的数据。

**依据：** [数据迁移](../database/migrations/2026_11_12_163459_add_crypto_defaults_to_system_configs.php)、[配置 Seeder](../database/seeders/CryptoSettingsSeeder.php)、[迁移测试](../tests/Feature/CryptoSettingsMigrationTest.php)。部署步骤与参数见[方案第 6.8 节](blockchain-payment-plan.md#68-当前第三批代码的落地边界未开放收款)。

## D-43 应用级组合付款与动态逐笔收款

**状态：当前工作区实现，未提交（2026-11-13，按用户确认的最新方案）。** 仅新增父单表，不创建子单草稿，不提前创建所有远端支付；每次客户发起当前付款才创建真实 orders 子单。此方案替代讨论中的预拆草稿和全程固定支付方式方案：每轮允许重新选择原支付组内有权使用的渠道，已发起的一笔仍锁定原渠道和远端引用。

后续修订：每轮重新选渠道的规则已由 [D-45](#d-45-组合付款优先沿用已选支付渠道) 替代，改为原渠道可用时持续沿用。应用级开关及开启后修改应用通知版本的规则已由 [D-50](#d-50-支付组控制组合付款开关与最高金额) 替代，改为支付组独立开关和整单上限，组合单固定 V2；其余父子单、拆分金额和通知规则保留。

**原因与影响：** 单笔限额不足时进入独立组合收银台；本轮优先一次付清余额，否则在最高三个合格上限中随机选渠道，并随机收取上限的 70%～90%。日/月可用额度须能覆盖完整上限，真实订单占用在渠道锁内复查；不保证未来付款额度。父单锁和当前子单指针限制为串行付款，网络失败不可用新号替代未决订单。

应用开启组合同时选择 V2 通知，原 V1 体保持；每笔首次成功通知包含父单进度、真实子单数组、event_id 和付款 version。商户原订单只在全部付清后成功，完整 JSON 参与旧算法签名。查询、物流、邮件、广告和子单结算约束见 [API 协议](../doc/api.md#组合付款与-v2-通知) 与 [数据设计](database.md#组合付款数据演进2026-11-13当前工作区)。父单不参与余额记账。部分付款到期或异常需要核对，不自动退款，不伪造物流号。

**依据：** [CombinedOrderService](../app/Services/CombinedOrderService.php)、[CombinedPaymentRouter](../app/Services/CombinedPaymentRouter.php)、[CombinedPaymentStatusService](../app/Services/CombinedPaymentStatusService.php)、[核心测试](../tests/Feature/CombinedPaymentTest.php)。

## D-44 组合付款排除 COPY/CREATE 商品模式

**状态：按用户明确要求采用，当前工作区实现，未提交（2026-11-13）。** 补充 D-43 的渠道资格规则：组合付款仅使用 `MATCH` / `VIRTUAL`，空配置仍按既有 `MATCH` 默认处理；`COPY` / `CREATE` 不参与首笔、后续随机付款或余额一次付清。普通订单仍允许这两种模式，不能因为组合限制把本可普通全额付款的订单改成组合单。

**原因与影响：** 组合子单使用本笔金额的付款明细，未实现父单原商品到子单的分配；直接使用 COPY 会把付款描述当作商品名。按用户选择限制渠道，不改写现有创建/复制商品规则。组合报价和每次付款前均复查模式，已有未决子单的远端补建和付款链接复用同样受限；不自动取消外部支付、不删除原子单或释放未决占用，已发生的真实付款继续登记。无合格渠道时返回原 `COMBINE_PAYMENT_UNAVAILABLE`。无数据库变更。

**依据：** [渠道资格](../app/Services/CombinedPaymentRouter.php)、[报价校验](../app/Services/CombinedOrderService.php)、[真实建单与续付](../app/Services/OrderCreationService.php)、[路由回归](../tests/Feature/CombinedPaymentRoutingTest.php)、[未决子单回归](../tests/Feature/CombinedPaymentTest.php)。

## D-45 组合付款优先沿用已选支付渠道

**状态：按用户明确要求采用，当前工作区实现，未提交（2026-11-13）。** 替代 D-43 中每轮重新随机选渠道的规则；优先沿用已选报价渠道，否则使用最近一笔已付子单的渠道。报价过期或有其他限额更高的渠道不构成切换理由；原渠道不能一次收清但能按既有规则拆付时继续使用。原渠道可收清则一次收尾。

**切换条件：** 原渠道已停用/删除、不在商户可用范围或原支付组内、商品模式不符合 D-44，或日/月金额、日笔数、手续费及金额精度无法满足本笔规则。首次尚未选定渠道，或原渠道确实不合格时，才按原规则优先找可一次收清的渠道，否则从最高三个合格上限随机选一。切换后继续沿用新渠道，旧渠道恢复不自动回切；所有金额仍按原规则计算。

**数据与资金边界：** 使用已有报价 JSON 和按序号排序的已付子单记录，不新增字段或迁移。未决子单仍受 `current_order_id` 保护，网络失败不能换渠道另建一笔；回调、资金、查询和物流协议不变。依据见 [路由实现](../app/Services/CombinedPaymentRouter.php)、[渠道回归](../tests/Feature/CombinedPaymentRoutingTest.php)、[逐笔付款回归](../tests/Feature/CombinedPaymentTest.php)。

## D-46 组合订单及商户配置的独立查看权限

**状态：按用户要求采用，当前工作区实现，未提交（2026-11-13）。** 新增 `combined_orders.view`、`checkout_links.view`、`merchant_domains.view`、`replace_keywords.view`，可在「角色权限分配」独立授予。组合订单不再借用普通订单 `orders.view`；只能查看组合订单的账号可看父单和子单摘要，不能进入完整子单详情。

**读写边界：** 收款链接、自有域名、关键词替换的 `manage` 继续包含查看，`view` 本身不授予任何写权限。列表提供只读详情；创建、编辑、删除、关键词导入与域名验证显式验证管理权限和记录归属。仅隐藏按钮不足以授权，已打开操作提交时仍检查权限；商户用户只看本商户，商户级管理员只看名下商户，启用的超级管理员保留全局访问。

**数据与兼容：** 复用 Spatie 权限表，无迁移。新增权限由现有 Seeder 或角色模板保存补齐；新商户内置订单、物流、财务管理员模板增加组合订单查看，保持这些默认角色原来的组合订单可见能力。存量已保存模板和自建角色不自动扩权，需由超管按需勾选并保存同步。依据见 [权限定义](../app/Support/Permissions.php)、[角色模板](../app/Services/MerchantRoleProvisioningService.php)、[只读边界回归](../tests/Feature/Filament/ResourceViewPermissionsTest.php)、[组合订单回归](../tests/Feature/CombinedPaymentAdminTest.php)。

## D-47 链上支付与法币组合付款的合并边界

**状态：当前工作区合并兼容策略，未提交（2026-11-13）。** 用户要求消除两分支冲突并保留区块链支付及拆单组合付款。本次保留各自已实现的资金口径：链上支付仍是独立普通单和独立币种账本；组合付款只汇总法币真实子单。不据此推定已支持跨币种组合付款。

**原因与实现：** 现有组合父单保存两位法币总额，通过网关状态汇总；链上核验按最小币单位入账，不存在对应的父单累计协议。`CombinedPaymentRouter` 在报价、复用、建子单和续付入口排除 crypto；普通路由仍可选链上渠道，组合开关不阻止独立链上支付。混合组是否需拆单按各渠道实际口径判断：法币使用现有汇损汇率，crypto 使用原始汇率 USD；链上开关关闭时排除对应候选。

后续修订：组合开关移至支付组，通知版本按订单保存，见 [D-50](#d-50-支付组控制组合付款开关与最高金额) 与 [D-51](#d-51-通知协议随支付组自动确定并按订单保存)；链上与法币账务边界保持。

**额度与通知：** 保留组合分支新增的未决额度锁定读，并为 crypto 使用相同原始汇率金额；链上原子入账同步更新 consumed 状态。API 同时保留父子身份和链上公开状态；V2 订单的链上通知仍是独立 `single` 事件，不产生法币结算。公开组合页的四种请求各自限流，状态轮询不消耗付款配额。

**依据：** [统一下单](../app/Services/OrderCreationService.php)、[渠道路由](../app/Services/PaymentService.php)、[组合路由](../app/Services/CombinedPaymentRouter.php)、[共存回归](../tests/Feature/CryptoCombinedPaymentCompatibilityTest.php)、[链上补查回归](../tests/Feature/CryptoCheckoutTest.php)。不新增数据库迁移，不改变钱包签名／归集和审核取款边界。

## D-48 组合收银台仅英文且完成后不返回商户

**状态：按用户明确要求采用，当前工作区实现，未提交（2026-11-13）。** 组合付款的客户收银台只显示英文，移除语言切换入口；旧中文参数和会话语言偏好不再生效，报价及发起付款的错误提示也固定英文。

**完成行为：** 订单付清后显示 “Your order has been paid in full.”，不提供返回商户链接，不自动跳转商户页面。首次加载已付清订单和轮询确认最后一笔均使用该提示，并隐藏付款说明及操作；后续短暂网络错误不覆盖已经确认的付清提示。原币累计金额和付款记录仍可查看。

**接口边界：** 收银台状态接口不再下发 `return_url`，父单保存的商户地址不删除；发送给 WordPress 的子单回跳仍指向组合收银台。商户以原 webhook 和查询协议确认成功。此决定仅调整组合收银台，不修改普通付款流程、后台及 API 文档的语言支持，无数据库迁移。依据见 [入口](../app/Http/Controllers/CombinedPaymentController.php)、[页面](../resources/views/payment/combined.blade.php)、[交互](../resources/js/combined-payment.js)、[页面回归](../tests/Feature/CombinedPaymentTest.php)、[前端状态回归](../tests/JavaScript/combined-payment.test.mjs)。

## D-49 组合物流回传用父级原商户号定位订单

**状态：按用户明确要求采用，当前工作区实现，未提交（2026-11-13）。** 商户网站物流接收端通常只认识原商户订单号，因此组合子单的物流回传将 `merchant_order_no` 改为父级原号，另用 `child_merchant_order_no` 保存真实子商户号。子单系统号 `order_no` 及原有父单引用继续保留，完整请求体参与原 HMAC 验签；具体字段与示例见 [API 协议](../doc/api.md#商户物流上传与回传)。

**边界与兼容：** 每个子单仍独立保存物流、生成回传快照和重试；商户可按原商户号 + 真实运单号去重。普通订单回传、商户上传物流的子商户号、支付站点物流同步和支付结果 webhook 不变。无表结构变更，既有审计快照不重写；需要更新旧回传时，通过订单详情手动推送生成新格式快照，同时停止该子单旧待发或重试任务。依据见 [回传服务](../app/Services/MerchantShipmentSyncService.php)、[物流回传回归](../tests/Feature/MerchantShipmentSyncTest.php)。

## D-50 支付组控制组合付款开关与最高金额

**状态：按用户明确要求采用，当前工作区实现，未提交（2026-11-14）。** 组合付款开关从应用移到支付组，支付组另设整笔组合订单的 USD 最高金额。开启必须填写正数、最多两位小数；按实际汇率（含汇损）折算后的总应付金额比较，等于上限允许，超过则返回 422 / `COMBINE_AMOUNT_LIMIT_EXCEEDED`，不保存父子单或创建远端支付。缺失或非正数上限不表示无限额。

后续修订：下述“应用通知版本控制普通单”的规则已由 [D-51](#d-51-通知协议随支付组自动确定并按订单保存) 替代，应用不再提供协议选择；支付组组合开关自动决定新普通单的 V1/V2，并在订单上保存。

**生效范围：** 同一应用下，各支付组独立配置。统一下单服务在商户→支付组锁内复查开关和上限，API、手工下单与公开收款链接共用。上限仅控制新组合单；普通单和指定渠道保留原规则，已建父单可幂等获取并继续付清，后续关闭组合开关或降低上限不截断付款。

**通知与结构：** 移除应用开关及保存时强制 V2 的逻辑，应用通知版本仅控制普通单；组合父单始终固定 V2，不修改应用设置。支付组新增布尔开关（默认关闭）及可空 decimal(15,2) 上限，应用删除原开关字段。按用户说明当前尚未上线，仅做结构调整，不搬迁旧应用配置、不回填、不保留旧字段兼容。此决定替代 D-43 的开关归属和应用通知联动约定。

**依据：** [下单服务](../app/Services/OrderCreationService.php)、[支付组表单](../app/Filament/Resources/PaymentGroupResource.php)、[组创建服务](../app/Services/PaymentGroupMethodService.php)、[字段迁移](../database/migrations/2026_11_14_143454_move_combined_payment_settings_to_payment_groups.php)、[创建回归](../tests/Feature/CombinedPaymentTest.php)、[后台回归](../tests/Feature/CombinedPaymentAdminTest.php)。协议见 [API 手册](../doc/api.md#支付组开关金额上限与创建)。

## D-51 通知协议随支付组自动确定并按订单保存

**状态：按用户提出的简化规则采用，当前工作区实现，未提交（2026-11-14）。** 应用移除通知协议选择及 `webhook_version` 字段。支付组开启组合付款时，该组新普通单与组合单都使用 V2；关闭时新普通单使用 V1，无额外配置开关。

**订单快照：** `OrderCreationService` 在普通单建单时写入 `orders.webhook_version`，组合父单固定 V2，子单继承父单版本。首次通知读订单版本，重试继续使用原通知快照；后续切换组开关、幂等重提、关闭组合后的子单续付都不改变已经确定的协议。字段扩展和验签规则本身不变。

**结构与范围：** 新增 orders 的 unsigned tinyint 版本列（默认 1），删除应用版本列；按当前测试阶段要求不迁移旧配置、不增加旧字段兼容读取。该决定替代 D-50 中应用为普通单单独选择协议的部分。依据见 [下单服务](../app/Services/OrderCreationService.php)、[通知服务](../app/Services/OrderNotificationService.php)、[结构迁移](../database/migrations/2026_11_14_172734_move_webhook_version_from_applications_to_orders.php)、[协议及签名回归](../tests/Feature/CombinedPaymentTest.php)、[后台回归](../tests/Feature/CombinedPaymentAdminTest.php)。

## D-52 组合付款仅允许超管或直属管理员开启

**状态：按用户要求采用，当前工作区实现，未提交（2026-11-14）。** 创建启用组合付款的支付组，或将现有支付组从关闭改为开启，只允许启用的超级管理员，或商户当前 `owner_id` 指定且具有支付组管理权限的商户级管理员。绑定具体商户的普通账号即使具有支付组管理权限，也不能开启；其他商户的管理员同样没有授权。

**执行与范围：** 表单禁用无权限的启用入口，创建和编辑保存时在商户行锁内另行校验；不信任传入开关或页面首次加载时的归属。普通商户保留原有支付组管理能力，可以保存已经开启的状态或关闭，但关闭后不能重新开启，旧页面也不能恢复已经关闭的开关。关闭后既有组合父单续付、订单通知协议快照仍沿用 D-50 / D-51，无新增表字段或权限项。

**依据：** [权限与创建服务](../app/Services/PaymentGroupMethodService.php)、[支付组表单](../app/Filament/Resources/PaymentGroupResource.php)、[编辑保存校验](../app/Filament/Resources/PaymentGroupResource/Pages/EditPaymentGroup.php)、[权限与伪造提交回归](../tests/Feature/CombinedPaymentAdminTest.php)。

## D-53 API 文档按协议版本独立展示

**状态：按用户要求采用，当前工作区实现，未提交（2026-11-14）。** `/docs/api` 默认显示最新 2.0，语言切换右侧提供版本按钮，通过 `?version=1.0|2.0` 选择。两种语言都使用各自独立的 `api_docs_v1.php` / `api_docs_v2.php` 接口正文，共享签名和附录由原 `api_docs.php` 提供。版本由固定白名单映射到语言文件，非法值返回 404。

**内容与边界：** 1.0 展示普通订单和 V1 支付通知，不夹杂组合付款字段、示例和错误码；2.0 展示普通单与组合付款，普通单示例也采用完整 V2 通知。两版仍说明当前共用的 API 路径，切换只影响文档，不改变订单协议或后台配置。语言切换保留所选版本，版本切换保留语言，两者都保留稳定章节锚点。公开配置附录和不读取私有应用凭证的约束保留，无数据库变更。

**依据：** [入口](../app/Http/Controllers/ApiDocumentationController.php)、[页面](../resources/views/api-documentation.blade.php)、[中文 1.0](../lang/zh_CN/api_docs_v1.php)、[中文 2.0](../lang/zh_CN/api_docs_v2.php)、[英文 1.0](../lang/en/api_docs_v1.php)、[英文 2.0](../lang/en/api_docs_v2.php)、[版本与示例回归](../tests/Feature/ApiDocumentationTest.php)、[双版公开配置附录](../tests/Feature/ApiDocumentationReferenceTest.php)。

## D-54 Telegram 平台机器人快速绑定

**状态：按用户提出的简化需求实现，当前工作区未提交（2026-11-16）。** 商户不再需要各自创建机器人和查找 Chat ID。平台统一配置一个机器人，商户在服务配置页选择个人或群聊，通过 Telegram 深链接完成绑定；原有自建机器人仍可使用。当前沿用每商户一个接收目标，个人与群聊二选一，新绑定成功才替换旧目标并启用通知。

**权限与凭证：** 平台 Token 只由服务端部署配置持有。未绑定或已关闭通知的商户不自动收到通知，自建模式不回退平台凭证。绑定链接 10 分钟有效、仅可消费一次；重新生成、保存 Telegram 配置或解除绑定都会撤销旧链接，避免旧请求覆盖后续设置。数据库仅保存令牌摘要。生成和回调都检查账号、商户启用与当前管理权限，商户转交后原管理员的链接失效；群聊另校验 Telegram 群主/管理员。回调独立验证 Telegram Secret Header，不复用商户 API 签名或后台登录态。

**实现与运维：** 商户行锁串行化配置变化，目标更新与令牌消费同事务；重复回调不重复改绑，群管理员查询失败返回 503 等待上游重试。页面轮询只更新 Telegram 区块。平台通过 `telegram:register-webhook` 校验机器人用户名和群组能力后注册回调，不丢弃既有待投递消息。部署需执行新增迁移并完成平台机器人配置，操作见 [部署手册](../doc/deployment.md#31-telegram-快速绑定)。

**依据：** [绑定服务](../app/Services/TelegramBindingService.php)、[回调控制器](../app/Http/Controllers/TelegramWebhookController.php)、[通知服务](../app/Services/TelegramNotificationService.php)、[配置页面](../app/Filament/Pages/ServiceSettings.php)、[绑定回归](../tests/Feature/TelegramBindingTest.php)、[页面回归](../tests/Feature/Filament/ServiceSettingsTest.php)、[注册命令回归](../tests/Feature/TelegramWebhookRegistrationTest.php)。

## D-55 区块链运行配置集中管理与密钥加密

**状态：当前工作区实现，未提交（2026-11-16）。** 用户确认 `APP_URL` 保留环境配置，其他区块链平台运行参数统一放入 `system_configs`。本条替代 D-35～D-42 关于 Tatum 凭证、开关、网络策略继续从环境变量读取的部分，不改变钱包只读、商户公钥模板和独立账本边界。

运行服务、回调和命令经 `CryptoRuntimeSettings` 读取数据库，不在服务提供者启动阶段加载数据库配置，也不把解密凭证写入 Laravel 配置缓存。普通参数保留原值列；API Key／HMAC 用独立 encrypted TEXT 列，普通值清空、模型隐藏、后台只显示已设置状态，输入留空保留，显式清除才删除。共享缓存仅保存密文，保存后失效，事务内不缓存未提交配置。

后台仍只允许启用的超级管理员保存。验证 HTTPS origin、哈希、确认数、时间范围、密钥长度及测试合约地址。凭证修改和订阅创建以配置行锁串行；任一使用相关凭证的非冷订阅存在时，拒绝直接替换／清除，以免通知失验或无法退订。安全退订完成后允许更换，新代次创建前同步 HMAC。回调域名变更仅用于新代次，部署方继续保留旧回调入口。确认策略仍按请求冻结。新下单开关与既有订单处理分开，关闭新下单不会停止原付款页和后台核验入账。

新增迁移一次性读取旧有效配置缓存或环境，优先保留数据库已有值；新安装保持关闭。完成迁移后 `.env` 旧键失效，不作为运行回退。数据回滚保留配置，有密文时禁止删除密钥列。未执行真实供应商请求或业务库迁移。

**依据：** [读取入口](../app/Support/CryptoRuntimeSettings.php)、[安全保存](../app/Services/CryptoRuntimeSettingsService.php)、[配置模型](../app/Models/SystemConfig.php)、[迁移](../database/migrations/2026_11_16_163325_move_crypto_runtime_settings_to_system_configs.php)、[密钥测试](../tests/Feature/CryptoSecretSettingsTest.php)、[运行配置测试](../tests/Feature/CryptoRuntimeSettingsTest.php)、[部署说明](../doc/deployment.md#32-区块链系统配置与升级)。

## D-56 管理员 Telegram 与支付通道通知归属

**后续修订：** 下述通知接收人和无归属时广播规则由 [D-58](#d-58-telegram-按业务归属统一管理) 替代；管理员绑定能力保留。

**状态：按用户确认采用（2026-11-16）。** 超级管理员和商户级管理员在账号设置页给自己保存 Telegram 接收目标，不在「平台管理 → 管理员」编辑页。可选平台机器人的个人或群聊快速绑定，也可填写自建 Bot Token 和 Chat ID。规则与商户服务配置相同：每名管理员只有一个接收目标，保存自建配置会替换快速绑定；快速绑定未完成时不能只保存平台模式。链接只能由本人生成和完成。账号停用或不再是平台账号后，未完成链接失效。这块配置与姓名、密码分开保存。普通商户用户仍使用服务配置里的商户机器人。

**通知：** 支付通道因网关账号不可用或连续建单失败被自动禁用时，默认仍按通道归属发送：商户自有通道只通知该商户；系统通道通知 `owner_id` 对应管理员。没有归属管理员时通知所有启用的超级管理员。订单、物流和支付组无可用通道的通知默认仍发给商户。接收人可以在「通知接收人」页面改掉，见 [D-45](decisions.md#d-45-telegram-通知接收人可配置)。未绑定或未启用则跳过。

**依据：** [账号设置](../app/Filament/Auth/EditProfile.php)、[绑定服务](../app/Services/TelegramBindingService.php)、[通道通知](../app/Services/PaymentChannelTelegramNotifier.php)、[回归](../tests/Feature/AdminTelegramBindingTest.php)。

## D-57 Telegram 通知接收人可配置

**已替代：** 接收人选择方案由 [D-58](#d-58-telegram-按业务归属统一管理) 替代，以下保留为历史记录。

**状态：按用户确认采用（2026-11-16）。** 超级管理员在平台管理的「通知接收人」页面，为每一种正在发送的 Telegram 通知勾选接收人。全平台共用一套规则，商户和商户级管理员不能改。可选：事件所属商户、通道归属、使用该通道的商户、全部已启用超级管理员、不发送。「不发送」不能和其他选项同时选择。没有对应通道的通知不能选通道归属或使用该通道的商户。

**默认与未配置：** 没保存过的模板沿用原来的接收人。通道自动禁用发给通道归属；站点商品同步发给使用该通道的商户；其余订单、退款拒付、争议、物流和没有可用通道发给事件所属商户。无效或空的保存值回退到这个默认，不因此停发。付款链接邮件和绑定测试消息不进入这个页面。

**发送：** `TelegramNotificationRouter` 在各通知入口解析接收人。同一条消息里的订单内容会发给每一个被勾选的对象。同一商户或同一管理员只发一次。没有可用机器人、已关闭或触发限流时跳过，不影响原业务结果。物流异常在有可用接收人但发送失败时继续重试；物流回传失败没有可用接收人时仍标为 `unavailable`，选择不发送则结束这批提醒，避免反复重试。

**依据：** [接收人页面](../app/Filament/Pages/TelegramNotificationRoutes.php)、[路由](../app/Services/TelegramNotificationRouter.php)、[回归](../tests/Feature/TelegramNotificationRouterTest.php)、[页面回归](../tests/Feature/Filament/TelegramNotificationRoutesPageTest.php)。

## D-58 Telegram 按业务归属统一管理

**后续修订：** 正文和订单信息的分框编辑由 [D-59](#d-59-telegram-完整消息统一编辑) 替代；本节归属与发送策略保持。

**状态：按用户确认的归属规则实现，当前工作区未提交（2026-11-16）。** 仅完善 Telegram，分为商户通知 13 项和支付通道通知 7 项。商户通知按订单/事件/任务/支付组所属商户发送；支付通道通知包含禁用、连续失败禁用、支付链接创建失败、源网站建单、源网站付款成功和商品同步完成/失败，均按当前通道归属发送。商户专属通道发给商户，系统通道发给 `owner_id` 对应管理员；源网站两项不经商户反查管理员。系统通道缺少归属时跳过，不回退其他管理员，不广播已分配商户。

**后台：** 超级管理员在「Telegram 通知管理」按两类查看、搜索和筛选，每条单独维护启用状态、正文、变量预览与适用策略。源网站付款成功次数默认 5（允许 1–5），无可用通道冷却默认 10 分钟、争议审核网关状态提醒冷却默认 1440 分钟，争议到期提醒默认提前 24 小时，未发货提醒默认超 10 天且系统时区每天 10:00。这些是通知参数，不修改下单、资金或物流异常判定阈值。恢复默认只影响当前项，实际接收人预览不发送消息、不暴露 Token 或 Chat ID。

**正文与存储：** 白名单变量仅做字符串替换和 HTML 转义，不执行代码；订单信息在消息内容编辑区单独展示，允许按通知调整字段、顺序和文案，预览与发送共用模板。该区块追加在正文后，汇总消息对每笔订单重复渲染；留空恢复默认订单信息。配置 `order_template` 最大 1000 字符，替换后的订单信息同样限长，给正文保留消息长度预算；历史配置缺少此项时使用默认，不改写已有正文。新设置保存在 `system_configs.telegram.notification_settings`，首次保存创建并按项合并；无新增表或迁移。旧路由选择失效，仅在尚未保存新设置时继承旧 `none` 停发状态。用户明确不需要发送记录，因此不新增通知历史表或页面；已有物流重试状态、争议提醒去重和运行错误日志继续保留。

**依据：** [通知类型](../app/Support/TelegramNotificationKind.php)、[配置](../app/Services/TelegramNotificationSettings.php)、[模板](../app/Services/TelegramMessageTemplate.php)、[发送路由](../app/Services/TelegramNotificationRouter.php)、[后台](../app/Filament/Pages/TelegramNotificationRoutes.php)、[配置回归](../tests/Feature/TelegramNotificationSettingsTest.php)、[调度回归](../tests/Feature/TelegramNotificationScheduleTest.php)。

## D-59 Telegram 完整消息统一编辑

**状态：按用户明确要求实现，当前工作区未提交（2026-11-17）。** 正文与订单信息合并为一个完整消息模板，编辑框直接回填全部文字和变量。默认消息继续包含订单信息，但用户删除或重排后，发送时不再追加隐藏内容。预览使用示例数据，与实际发送共用渲染、转义、限长和分条路径。

**变量与汇总：** 原事件变量含义不变；订单信息使用 `:order.*`，避免退款金额、折算金额覆盖订单原币金额。未发货及物流回传失败支持一组 `[orders]…[/orders]`，每笔订单重复该段，段外文字作为每条消息的开头和结尾。物流回传的应用名、商户引用号、运单号、失败原因改为可编辑的 `:shipment.*`；订单及物流变量仅允许出现在重复区内。允许删除整个重复区只发送汇总文字，不允许空重复区作为整条消息。分条时保持每笔订单与提醒 ID 对应，物流回传最多每条 5 笔。

**兼容与长度：** 同一配置 JSON 使用 `message_template` 区分新完整模板；旧 `template` 与 `order_template` 读取时合成，不写库、不覆盖自定义文案，下次保存只替换当前通知项。空模板使用完整默认值。模板最多 4000 字符；动态事件值、订单值和物流值分别沿用 200／100／50 字符限制，输出按转义后的 UTF-16 长度限制为 4000，不切断 HTML 实体或 UTF-16 字符对；汇总为订单重复区保留至少 2000 的长度预算，过长单段按预览规则截短。只做白名单替换，不执行模板代码；不新增迁移、接收人选择或发送记录。

**依据：** [统一模板](../app/Services/TelegramMessageTemplate.php)、[兼容读取与保存](../app/Services/TelegramNotificationSettings.php)、[单框编辑与预览](../app/Filament/Pages/TelegramNotificationRoutes.php)、[渲染回归](../tests/Feature/TelegramMessageTemplateTest.php)、[编辑与发送一致性回归](../tests/Feature/Filament/TelegramNotificationRoutesPageTest.php)。


## D-60 商城插件同仓管理、独立发布与 API 协议同步

**状态：按用户明确要求实现，当前工作区未提交（2026-11-17），未生产发布。** WordPress PayPal 与信用卡源码迁入 `plugins/wordpress/`，各自保留 slug、主文件及配置标识，以独立 `plugins/{slug}/v{version}` tag 构建安装包。Laravel 负责插件目录、公开元数据和下载，版本文件保存在配置磁盘，生产支持 OSS；运行应用的部署版本不充当插件最新发布版本。

**更新兼容：** 原独立仓库保留过渡 Release，让已安装的旧 GitHub 更新器取得新包。新更新器按插件标识请求本项目，使用固定版本地址，校验大小和 SHA-256；更新请求不携带商户 API Key 或 GitHub Token。稳定元数据只在上传完成后切换，同一版本不同包或协议拒绝覆盖。商城插件使用 PHP 7.4 兼容语法，独立于 Laravel 服务端检查。

**协议同步：** 商户 API 请求、响应、签名、通知和物流实现与文档进入 `PluginProtocol` 指纹范围；变化必须同时检查两个插件、补充适配回归、更新指纹并递增版本，CI 与打包命令检查该要求。v2 仍沿用原订单 API 路径；组合付款只有父单付清才确认商城支付，部分付款、重复及乱序通知保留进度，不因重试另造商户订单号。通知事件采用完整体验签，金额以十进制字符串核对，物流映射到真实子单。

**依据：** [协议检查](../app/Support/PluginProtocol.php)、[发行服务](../app/Services/PluginReleaseService.php)、[客户端状态处理](../plugins/wordpress/sscpay-payment-paypal/includes/class-sscpay-paypal-order-state.php)、[服务端与插件契约](../tests/Feature/WordPressV2ContractTest.php)、[发布操作](../doc/deployment.md#电商插件发布与更新)。

## D-61 日常部署自动同步插件

**状态：按用户明确要求实现，当前工作区未提交（2026-11-17），未在线上执行。** 补充 D-60 的发布入口：日常上线统一运行 `deploy.sh`，由 `plugins:sync` 自动发现、构建并发布已接入插件，使源码部署同时生成下载中心所需安装包和稳定索引。GitHub tag 发布继续负责 Release 附件和旧仓库镜像。

**原因：** 只部署源码不会同步 Git 忽略的本地安装包，下载页面可能显示“安装包尚未发布”；手工记忆插件发布、缓存和队列命令容易遗漏。用户要求将后续上线必需操作统一纳入部署入口。

**执行身份：** root 启动时自动识别账号：优先采用本站运行目录的唯一非 root 属主，其次采用项目目录与 `.env` 的唯一非 root 属主，均无证据时才参考 PHP-FPM worker 的唯一非 root UID；每级出现冲突、无候选或 UID 无系统账号时停止。非 root 启动沿用当前账号；`DEPLOY_USER` 保留为显式覆盖，不能与实际执行身份不符。通过 `runuser` 或非交互 `sudo` 切换后，先检查代码、Git、依赖、缓存、插件及前端目录和已有文件的实际权限，再创建锁并部署。`--check` 仅预检，不进入维护模式；权限不足报告路径，不自动修改属主或 ACL。此方式使普通部署无需反复指定账号，并解决非交互 Composer 因 root 禁用插件的问题。

**前端运行环境：** 登录账号与部署账号可能使用不同的 Node。按显式 `DEPLOY_NODE_BIN`、站内工具目录、`/opt/sscpay-node/bin`、原 PATH 的顺序选择可访问的配套 Node/npm，Node 兼容性在维护模式前检查；npm 安装启用严格引擎校验。缓存固定到站点私有的 `storage/app/deploy-cache/npm`，预检并准备目录，内容缓存允许只读，索引与日志需要可写。既有 nvm 运行时可由管理员一次性迁入共享目录，部署脚本不安装系统软件或修改 root 家目录权限。

**影响与边界：** 脚本在维护模式下更新，并在迁移前完成协议和插件版本预检；失败停止且保留维护状态，不自动回滚数据库。相同版本内容可重试，缺失索引或 ZIP 可由相同源码补齐；版本冲突、降级仍拒绝发布。使用配置中的插件磁盘，OSS bucket/prefix 需与网页运行环境一致；本地存储需要持久化，多实例部署由平台统一调度。预检不试写远端，多个插件的稳定入口不作为一个存储事务切换。

**依据：** [部署脚本](../deploy.sh)、[同步命令](../app/Console/Commands/SyncPluginReleases.php)、[发布服务](../app/Services/PluginReleaseService.php)、[命令回归](../tests/Feature/SyncPluginReleasesTest.php)、[隔离的部署流程回归](../tests/Feature/DeploymentScriptTest.php)、[部署操作](../doc/deployment.md#9-日常自动部署)。

## D-62 全额退款后转拒付仅补扣拒付费

部分退款的人工确认入口与剩余本金规则由 [D-63](#d-63-部分退款人工转拒付扣剩余本金及拒付费) 补充；以下全额退款规则保持。

**状态：针对本次退款转拒付需求在当前工作区实现，未提交（2026-11-17）。** 拒付拦截可能先以退款通知到达；已有全额退款账务的订单可由具备拒付权限的人员在订单详情页转为拒付，只额外收取一次拒付手续费。普通退款通知不自动判定拒付，部分退款继续要求人工核对本金。

**原因与影响：** 原实现禁止所有有退款金额的订单拒付，无法补扣拦截费；直接再扣全额会重复扣本金。复用 `BalanceService::chargeback()`，在商户→订单锁内重读退款金额和费用快照，保留原退款本金、退款费及结算审计，只扣拒付费。全额退款转换追加零本金的 `chargeback` 凭证，兼容既有防重、主状态推导、可靠待办和待入账筛选，零手续费也有凭证。真实网关拒付确认复用该路径；重复回调或查询不再扣费，后续 `paid/refunded` 结果不覆盖拒付事实。

后台按钮使用“退款转拒付（补扣手续费）”，弹窗金额按订单 `chargeback_fee_snapshot` 展示并扣款，旧单 NULL 回退原渠道配置，显式 0 不回退。沿用 Resource 商户范围、提交时权限复查及 `FinanceSecurity` TOTP，不新增远端退款动作、迁移或历史批量转换。统计保留原退款，转换当天计一笔拒付、拒付新增本金为 0，费用仍独立记账。

**依据：** [账务服务](../app/Services/BalanceService.php)、[订单详情动作](../app/Filament/Resources/OrderResource/Pages/ViewOrder.php)、[退款与拒付回归](../tests/Feature/GatewayRefundChargebackTest.php)、[后台权限与 TOTP 回归](../tests/Feature/Filament/OrderLifecycleActionsTest.php)、[结算分类回归](../tests/Feature/OrderSettlementBalanceTest.php)、[统计回归](../tests/Feature/OrderStatsAggregatorTest.php)、[MySQL 并发回归](../tests/Feature/MySqlSettlementConcurrencyTest.php)。

## D-63 部分退款人工转拒付扣剩余本金及拒付费

**状态：用户明确选择“剩余本金＋拒付费”；当前工作区实现，未提交（2026-11-17）。** 例如 USD 100 订单已退 USD 30、拒付费 USD 15，本次人工转拒付再扣 USD 85。补充 D-62 的全额退款转换，不将普通退款自动认定为拒付。

**原因与影响：** 部分退款后再扣整单本金会重复扣款，只扣手续费又会漏掉剩余本金。后台“部分退款转拒付”展示已退本金、剩余本金、拒付费和合计，要求理由、拒付权限及 TOTP。`BalanceService::chargebackPrincipalAmount()` 在锁内按实际记账的 USD 退款计算剩余本金，核对退款单原币合计、USD 合计与本金台账；异常历史数据先核对，不猜金额。转换保留原退款和已收退款费；本金依次消耗本单待结算、本单循环保证金，再由可提现承担，拒付费直接扣可提现。

网关自动拒付遇部分退款仍转人工待办，不能用无操作人的自动路径代替金额确认。人工补录凭证后可核销原待办；后续网关又报退款时，不重复扣已由退款＋拒付结清的本金。可靠待办、生命周期与资金释放查询均识别拒付凭证，避免因原退款累计未达全额而永久阻塞出款。统计按实际退款本金与拒付剩余本金分别归日，不重复计本金；不新增迁移、商户 API 协议或远端退款操作。

**依据：** [账务服务](../app/Services/BalanceService.php)、[可靠记账](../app/Services/OrderAccountingService.php)、[生命周期](../app/Services/OrderLifecycleService.php)、[订单详情](../app/Filament/Resources/OrderResource/Pages/ViewOrder.php)、[退款与拒付回归](../tests/Feature/GatewayRefundChargebackTest.php)、[后台操作回归](../tests/Feature/Filament/OrderLifecycleActionsTest.php)、[MySQL 并发回归](../tests/Feature/MySqlSettlementConcurrencyTest.php)。

## D-64 OpenCart 两代独立安装包与共享协议客户端

**状态：按用户要求实现，当前工作区未提交（2026-11-17），未生产发布。** 用户要求同时支持 OpenCart 3.x、4.x。新增 `sscpay-opencart3` 与 `sscpay-opencart4` v1.0.0；每包注册 PayPal 和信用卡，不共用不同平台的可安装目录。3.x 使用传统 MVC 与 `upload/`；4.x 使用命名空间与扩展目录，兼容早期 `getMethod` / 字符串支付方式及后期 `getMethods` / 多选项结构，避免在公开动作地址依赖不同版本的方法分隔符。

**复用与边界：** 协议、金额计算、状态归约、存储和模板维护在 `plugins/opencart/shared/`，由构建白名单注入两个包。客户端无 Laravel 运行依赖，以订单币种汇率快照换算，沿用原商户号重试；完整签名通知及主动查询验证身份、币种和金额，组合单只在全额收齐后更新商城已付款分类。商城 MySQL 命名锁、独立状态、事件和物流去重共同防止重复状态副作用。管理端查询为只读，发货前验证全额付款并拆分到真实子单；不提供循环扣款或真实退款操作。

**发布与同步：** 两个包均加入下载中心、`plugins:sync`、协议与版本检查、CI 契约回归和 tag 发布；4.x 公开下载及 Release 附件固定名 `sscpay.ocmod.zip`，供安装器识别 `sscpay` 代码。OpenCart 后台提供版本、下载地址及校验值检查，升级通过安装包 / 覆盖扩展目录完成，保留订单记录。服务端 API 本次未变，不递增现有 WordPress 版本；以后协议关联文件变化须检查并递增全部四个包。

**验证边界：** 契约测试覆盖两代 PayPal / 信用卡、真实 Laravel API 建单与同号重试、分次付款通知、主动查询及双向物流；独立 MySQL 库验证建表、事务回滚、nonce 与同单四并发。打包后入口在模拟注册表中执行，本机 OpenCart 3.0.3.8 Twig 能渲染并转义模板；完整商城安装、4.x 运行时、主题 / 第三方结账、库存与优惠券联动及真实收款尚需专用测试站验收。

**依据：** [共享客户端](../plugins/opencart/shared/Gateway.php)、[两个安装包清单](../plugins/opencart/sscpay-opencart3/plugin.json)、[4.x 清单](../plugins/opencart/sscpay-opencart4/plugin.json)、[契约回归](../tests/Feature/OpenCartV2ContractTest.php)、[MySQL 回归](../tests/Feature/OpenCartStorageTest.php)、[操作说明](../doc/deployment.md#opencart-安装配置与升级)。


## D-65 退订后两轮地址补查替代持续扫块

**状态：用户确认，当前工作区实现，未部署（2026-11-18）。** 替代 D-35 的连续历史接管退订门槛和 D-38 的持续冷地址扫块；保留 RPC 核验、事件幂等、独立账本及人工异常款边界。

**决策：** 按整单首次实际退订时间固定 +72／+168 小时两轮地址补查，每三小时调度到期计划。每轮读取所有历史报价地址的完整地址交易索引，固定本轮链头并持久化分页；两轮以唯一键去重。第 7 天可覆盖停机遗漏的第 3 天并保留审计。订阅重开、重新报价或手动查询不新增这两轮。后台订单列表、详情和异常款页面支持即时入队的地址查询，现有审核重新核查接入同一任务，分别沿用当前租户权限和审核权限／TOTP。

**原因与费用：** 不再为无限增长的旧地址持续读块。两轮是业务查询次数，不是 HTTP 次数；分页、内部转账和交易核验仍按实际调用计费。失败最多三次后停止自动重试，保留错误并允许另发手动任务。已发现未确认交易继续按原确认任务处理，手续费和法币账务口径不变。

**一致性与边界：** 退订成功与计划落库同事务。地址 API 只给出交易线索，RPC 决定真实金额、规范链归属和确认结果；完整查询期间阻止提前自动入账。正常回调不再依赖不断刷新的历史扫描证明。迟到、少付、多付、历史地址同时来款及重组异常仍人工处理，不自动冲正。第 7 天以后新增转入／深度重组不再保证自动发现，需手动查询或合法链接重开；索引延迟和套餐／测试链权限须真实联调。

**部署与依据：** [增量迁移](../database/migrations/2026_11_18_111740_create_crypto_address_checks_table.php)、[计划与执行](../app/Services/CryptoAddressCheckService.php)、[地址分页](../app/Services/CryptoHistoryScanService.php)、[Tatum 适配](../app/Services/TatumAddressHistoryClient.php)、[调度](../routes/console.php)、[回归](../tests/Feature/CryptoAddressCheckTest.php)。`deploy.sh` 在迁移后自动初始化旧取消记录；保留审核数据，非空审计拒绝向下迁移。测试与人工验收步骤见[开发方案](blockchain-payment-plan.md#611-历史补查冷地址与自动入账第六批未开放收款)。


## D-66 售后订单独立列表与退款转拒付展示时间

**状态：用户确认方案后在当前工作区实现，未提交、未部署（2026-11-18）。** 平台和商户后台在订单管理分组增加“退款订单”“拒付订单”，复用原 `orders.view` 和商户数据范围，观察者面板不增加入口。列表只读并链接回原订单详情；查看售后时间、退款历史和拒付费用无需财务或资金操作权限，原资金动作仍检查各自权限及 TOTP。

**分类与时间：** 部分和全额退款进入退款列表；主状态、网关拒付事实或真实拒付本金凭证（含零本金）存在时，优先归入拒付列表，转换订单不重复出现在退款列表。退款时间取最后一次成功退款，普通拒付取首次本金流水时间；退款转拒付按原退款时间展示、排序和筛选。缺少本地时间时仅回退同商户、当前网关引用、已接受事件的远端时间或接收时间，仍无依据显示未记录。旧主状态与凭证不一致时只按事实查询，不批量改写历史订单。

**费用与边界：** 拒付费用按实际费用流水展示；零费用的本金凭证也视为已记账，防止配置变化后显示成新费用。未记账金额优先取订单快照，NULL 回退原渠道，显式 0 不回退，并标明待记账。详情保留逐次退款及退款费。展示时间不会回写资金流水或改变原统计归日；没有新增迁移、权限、API 字段、插件协议、远端退款或部署步骤，沿用既有资源发现及部署流程。

**依据：** [只读查询服务](../app/Services/OrderAfterSaleService.php)、[共享列表](../app/Filament/Support/OrderAfterSaleTable.php)、[退款入口](../app/Filament/Resources/RefundOrders/RefundOrderResource.php)、[拒付入口](../app/Filament/Resources/ChargebackOrders/ChargebackOrderResource.php)、[原订单详情](../app/Filament/Resources/OrderResource/Pages/ViewOrder.php)、[展示与权限回归](../tests/Feature/OrderAfterSaleDisplayTest.php)。

## D-67 回调交易核验合并与有界重试

**状态：本次请求量优化的当前工作区实现，未提交、未部署（2026-11-18）。** 保留 D-35 的验签线索、RPC 核验及原请求最低确认数快照，收敛重复观察和无上限重试；不变更入账、权限或商户 API 协议。

**订阅：** 新 EVM／TRON 订阅查询官方 `/v4/subscription/chains` 能力表，只有网络、环境及 `ADDRESS_EVENT` 匹配且 `finality=true` 才请求 `finality=final`。能力结果按凭证指纹加锁缓存一天；失败或结构不符时短缓存一分钟，沿用普通通知与同一套本地确认策略。BTC／LTC 不发送该选项，已有订阅不批量修改。接口能力及字段依据 [Tatum 链能力](https://docs.tatum.io/reference/getsupportedalertchains)、[创建订阅](https://docs.tatum.io/reference/createsubscriptionv4)。

**核验：** 在请求互斥下固定本轮开始前已存在的同商户、同请求、同交易收件集合，共用一次链上观察并保留各份原始摘要／线索。新到的回调不被旧观察完成；后续地址补查仍重新观察规范链，不用长期缓存替代重组核查。正常调度重新读取收件状态，跳过已由同组完成的记录。

**预算与恢复：** 未确认及失败观察以 BTC 600 秒、LTC 150 秒、其他网络 60 秒起步，指数退避最多一小时，下一次不越过首次接收后 24 小时。未完成记录到 48 次或 24 小时即转 `review`／`verification_exhausted` 并停止重查；积压零次记录仍先观察一次。新地址查询若查实已确认或无收入，可以更新预算耗尽收件的核验状态，其他复核原因保持。请求保留人工复核，按既有证据和 TOTP 流程处理；不自动接受迟付、冲正或释放资金。

**依据与部署：** [订阅客户端](../app/Services/TatumSubscriptionClient.php)、[核验服务](../app/Services/CryptoReceiptVerificationService.php)、[核验命令](../app/Console/Commands/VerifyCryptoReceipts.php)、[地址查询](../app/Services/CryptoAddressCheckService.php)、[请求量及恢复回归](../tests/Feature/CryptoReceiptPollingTest.php)、[订阅契约回归](../tests/Feature/TatumSubscriptionClientTest.php)。无新表、字段、配置项或依赖；沿用 `deploy.sh` 的代码、队列和调度更新流程。真实供应商 finality 行为及并发规模仍待隔离环境验收。

## D-68 带金额付款二维码与 TRC20 地址展示

**状态：按用户最终确认的简化方案在当前工作区实现，未提交、未部署（2026-11-18）。** TRC20 保留地址、二维码与转账金额展示，撤回本轮未提交的钱包连接／确认方案。真实手机钱包扫码兼容性尚未验收。

**格式与原因：** BTC／LTC 使用 `bitcoin:`／`litecoin:` 的十进制 `amount`，ETH／ERC20 使用 [EIP-681](https://eips.ethereum.org/EIPS/eip-681) 的链 ID、最小单位整数与冻结代币合约。TRC20 使用纯收款地址二维码，用户扫码填入地址、复制页面展示的精确应付数量，在钱包内完成转账，减少钱包浏览器连接和网页确认步骤。

**边界：** 金额与 ERC20 合约只取请求快照，缺少合约快照时回退地址二维码和复制金额。TRC20 地址二维码不依赖合约或创世区块快照，公开状态不下发 TRON 钱包转账参数。非 ready 状态不返回地址、URI 或二维码。页面不连接 TRON 钱包、不构建或签署交易，到账继续走既有核验和入账服务。

2026-11-19 的付款页调整在倒计时归零时立即撤下二维码、复制和钱包入口。到期或重新报价期间，以删除线呈现旧金额及同一浏览器标签页看过的旧地址。标签页仅存放对应付款链接最后一次实际展示的报价，新地址等服务端返回 ready 才展示。失联及离开页面时隐藏付款信息。

**依据与部署：** [付款数据](../app/Services/CryptoCheckoutPresenter.php)、[页面交互](../resources/js/crypto-payment.js)、[付款页](../resources/views/payment/crypto.blade.php)、[二维码回归](../tests/Feature/CryptoCheckoutQrTest.php)、[前端展示回归](../tests/JavaScript/crypto-payment.test.mjs)。没有新增迁移、依赖、商户 API 或商城插件协议，沿用 `deploy.sh` 现有资源构建与缓存步骤；完整二维码内容作为缓存键，旧二维码缓存不需手工清除。

## D-69 仪表盘统一筛选与统计明细

**状态：当前工作区实现，未部署（2026-11-19）。** 首页用页签展示报表，默认打开“数据统计”总览，随后依次显示有权限查看的代理统计、商户统计、应用统计和支付统计。切换时保留顶部筛选，只挂载当前页签的组件。两后台共用筛选和明细组件。侧栏移除订单统计和数据报表入口，旧订单统计地址沿用 `orders.view`，供已保存的链接及手动刷新继续使用。

**筛选与范围：** 超级管理员可选择统计周期、代理、商户、应用和支付方式。代理按商户当前 `owner_id` 匹配商户级管理员。商户级管理员从名下商户筛选，普通商户从本商户的应用筛选。选项逐级收窄，提交值按当前登录账号及上级选择重新校验。支付方式选项来自可见订单日汇总，包含有历史交易的共享及软删除渠道。

**影响：** 首页交易指标和明细共用已确认的筛选条件及原日汇总口径。明细沿用 `orders.view`，商户分组限平台人员，代理分组仅超级管理员可查。代理统计按商户当前归属汇总，没有商户级管理员归属的交易归入“平台直管”。余额卡继续读取实时余额。首页读取预先聚合的数据，聚合与手动刷新仍走既有入口。

**依据：** [仪表盘](../app/Filament/Pages/AdminDashboard.php)、[筛选与查询](../app/Services/DashboardService.php)、[明细表](../app/Filament/Widgets/OrderStatsBreakdownTable.php)、[页面测试](../tests/Feature/AdminDashboardTest.php)、[统计范围测试](../tests/Feature/DashboardOrderStatsAlignmentTest.php)。

## D-70 订单导出按账号类型控制支付方式列

**状态：当前工作区实现，未部署（2026-11-19，按用户要求）。** 商户在订单列表下载物流模板时，不输出支付方式列；商户级管理员和超级管理员输出支付方式的“名称”（`method_name`），不使用标识或展示名称替代。可见的软删除渠道保留名称，无法解析关联名称时留空。

**原因与影响：** 商户导出不包含内部渠道资料，平台账号仍可用名称核对订单。`LogisticsImportService::generateTemplate()` 接收明确的导出账号，重新读取其当前身份、启用状态和物流导出权限决定列；缺省不输出支付方式。订单查询继续沿用已有授权范围、筛选与分批读取。表头、说明行与金额列位置同步调整，文本单元格与数值金额格式保持原口径，商户可继续填写并上传模板；旧模板也能导入。观察者的独立导出沿用原有授权及字段，不变更商户 API 或商城插件协议。

**依据：** [订单列表导出入口](../app/Filament/Resources/OrderResource/Pages/ListOrders.php)、[模板导出与导入](../app/Services/LogisticsImportService.php)、[账号类型导出回归](../tests/Feature/Filament/OrderListFiltersTest.php)、[模板回传回归](../tests/Feature/LogisticsTemplateTest.php)。

## D-71 收款站插件同仓与本地测试包

**状态：2026-11-20 的初始导入记录；独立商品包方案已由 [D-73](#d-73-商品-api-并入收款站聚合插件) 替代。** 当时按用户指定放在 `plugins/merchant/`，保留两个独立插件，便于与 Laravel 调用方一起维护。

**来源与边界：** `sscpay-gateway-aggregator` 从原仓库 `v1.13.7`（`cc1694376d5a30dd4fb91603698a6a06a3cf4c9f`）导入；`sscpay-products-api` 从 `v2.0.0`（`b67bd067843cd9f18b11097599bc9ff72ad077f3`）导入。保留原业务代码、版本和更新器，源码保留 Composer 锁文件；各插件的生产依赖仅装到本地及测试 ZIP，不纳入主仓跟踪。旧仓库的发布工作流、现场配置、未跟踪文件和旧 ZIP 不迁入。

**影响与依据：** 这两个包是 WordPress 收款站的服务端接口，不是商户 API 客户端。不登记 `plugin.json`，不进入公开目录、更新接口或自动发布；[测试打包脚本](../plugins/merchant/build-test-zips.sh)仅在私有本地目录生成安装包。[安装与验收](../doc/wordpress/wordpress-site-setup.md#41-按站点用途安装插件)和[公开分发回归](../tests/Feature/PluginDistributionTest.php)记录本阶段边界。原站点与现有部署不变。

## D-72 商城结账客户 IP 透传

**状态：当前工作区实现，未部署（2026-11-20）。** 商城插件只读取 PHP 的 `REMOTE_ADDR`，依赖商城源站按其可信代理配置恢复客户端地址，不接受浏览器提供的任意转发头。验证单个 IPv4／IPv6 后作为可选字段随订单签名；未知时省略。

**原因与兼容：** 旧 API 的 `customer_ip` 列是调用者的连接地址，不能识别历史订单的买家。另加可空 `checkout_customer_ip` 保存商城签名上报的地址，旧值不迁移，原广告转化不改变。普通单与组合父单保留首次快照，重试不改；子单继承父单地址。收款站聚合插件使用 WooCommerce 原生订单字段；旧请求无地址时不展示 API 调用方地址。该字段不提供给第三方支付通道、商户查询／通知、公开付款页或导出。

**依据：** [商户请求](../app/Http/Requests/Api/CreateOrderRequest.php)、[统一建单](../app/Services/OrderCreationService.php)、[收款站建单](../plugins/merchant/sscpay-gateway-aggregator/src/Core/OrderManager.php)、[兼容用例](../tests/Feature/OrderCustomerIpTest.php)与[部署说明](../doc/deployment.md#商城客户-ip)。

## D-73 商品 API 并入收款站聚合插件

**聚合插件 `1.15.0` 已在本机 WordPress 验证。** 取消独立 `sscpay-products-api` 源码目录和构建目标。原商户 API、四个商城插件协议及 Laravel 商品同步请求不变。现在的网关分发由 [D-74](#d-74-网关插件随部署发布并在后台下载) 说明。

**商品兼容：** 保留 `GET /wp-json/wc/v3/products-with-variations`、兼容状态接口 `GET /wp-json/wc/v3/ninox-status` 和原响应字段。用有读取权限的 WooCommerce 密钥鉴权，要求所属用户具有 `manage_woocommerce`；继续支持 HTTPS query 和旧 Authorization 格式。缓存只按分页和商品版本区分，不使用凭证，缓存命中也附带总数和分页头。旧插件尚启用时由旧插件提供路由；停用后聚合插件接管，无须更改 Laravel 地址或重新生成商品密钥。

**回调密钥：** 仅有后台设置权限的管理员可查看、修改与保存。已保存的站点独立值优先；未保存时可从本站 `wp-config.php` 的 `PGA_DEFAULT_OUTBOUND_SHARED_SECRET` 预填并用于签名。默认值和 Laravel `PGA_WEBHOOK_SECRET` 需一致，不随源码或 ZIP 发布；此默认机制不改变回调签名算法及重试语义。

**依据：** [兼容商品接口](../plugins/merchant/sscpay-gateway-aggregator/src/API/Controllers/ProductCatalogController.php)、[收款站设置页](../plugins/merchant/sscpay-gateway-aggregator/src/Admin/SettingsPage.php)、[出站签名](../plugins/merchant/sscpay-gateway-aggregator/src/Core/OutboundSigner.php)、[升级步骤](../doc/wordpress/wordpress-site-setup.md#41-按站点用途安装插件)。

## D-74 网关插件随部署发布并在后台下载

网关聚合插件随 `deploy.sh` 构建并由 `plugins:sync` 发布，复用现有版本 ZIP、发布清单和稳定版入口。构建前安装锁定的 Composer 运行依赖，网关使用自身的 WordPress 插件版本。商户 API 指纹与公开插件目录继续使用四个商城插件的清单。本决策替代 D-71 中当时仅生成本地测试 ZIP 的分发范围。

平台和商户面板的支付方式创建、编辑页各使用本面板的下载地址。下载经过面板登录和 `payment_methods.manage` 权限校验，从私有存储流式返回已发布的稳定版，并附带 SHA-256 校验值。见[部署说明](../doc/deployment.md#网关插件的后台下载)和[下载回归](../tests/Feature/Filament/GatewayPluginDownloadTest.php)。

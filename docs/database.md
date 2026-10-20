# 数据库与重要模型

核对日期：2026-09-28。结构依据是 [database/migrations/](../database/migrations/) 的完整迁移链，运行语义依据是 [app/Models/](../app/Models/) 和 [app/Services/](../app/Services/)。本文不替代字段级迁移定义，也不代表已核验生产库。

## 1. 核心关系

```mermaid
erDiagram
    merchants ||--o{ applications : owns
    merchants ||--o{ payment_groups : owns
    merchants ||--o{ orders : owns
    merchants ||--o{ merchant_payment_methods : assigned
    payment_methods ||--o{ merchant_payment_methods : shared
    payment_groups ||--o{ payment_group_methods : contains
    payment_methods ||--o{ payment_group_methods : routes
    applications ||--o{ orders : authenticates
    payment_methods o|--o{ orders : selected
    orders ||--o{ order_items : original
    orders ||--o{ order_matched_items : gateway
    orders ||--o{ order_refunds : refunds
    orders ||--o{ order_dispute_events : reviews
    merchants ||--o{ merchant_balance_transactions : ledger
    checkout_links o|--o{ orders : creates
    checkout_links ||--o{ checkout_link_items : configures
    merchant_domains o|--o{ checkout_links : hosts
```

图为核心关系摘要；具体外键、可空与删除策略以迁移为准。`payment_methods.merchant_id` 还支持商户直接拥有渠道（系统级渠道该字段为 NULL）。

## 2. 租户、身份和渠道

| 表 / 模型 | 关键语义 |
|---|---|
| `merchants` / `Merchant` | 商户主体；`owner_id` 为所属平台管理员，`balance`/`frozen_balance` 为 USD 总余额/冻结额 |
| `users` / `User` | `is_super_admin`、`merchant_id` 区分超管、商户级管理员和商户用户；范围入口为 `manageableMerchantIds()` |
| `roles`、权限相关表 | Spatie 权限；商户角色有商户归属，平台共享角色另有范围。定义见 `app/Support/Permissions.php` 与 `PermissionSeeder` |
| `applications` / `Application` | 商户接入身份、`app_id`、加密 `api_key`、应用网站与邮件/广告配置 |
| `payment_methods` / `PaymentMethod` | 渠道、站点、网关配置、限额、费率、商品模式。`merchant_id=NULL` 表示系统级渠道；`owner_id` 标识维护者 |
| `merchant_payment_methods` | 系统级渠道分配给商户的多对多表；不是所有订单的商户归属表 |
| `payment_groups` / `PaymentGroup` | 商户的路由组，`group_key` 在该商户未删除记录内唯一 |
| `payment_group_methods` | 组内渠道，`group_id`/`method_id`，`priority` 为分流权重 |
| `payment_method_config_maps`、`payment_method_profiles` | 网关字段模板及渠道资料附表；profile 每渠道至多一条 |
| `observers`、`observer_payment_methods` | 独立观察者账户与允许查看的渠道；展示缩放不修改账务金额 |

`PaymentMethod::forMerchant()` 的含义是“商户可使用”，包含自有与已分配渠道；不能用普通商户外键过滤替代。`site_products.merchant_id` 也可为空，商品实际按支付渠道关联，避免假设所有业务模型均有非空商户 ID。

## 3. 订单及金额快照

`orders` / [Order](../app/Models/Order.php) 是中心模型：

| 字段组 | 语义与限制 |
|---|---|
| `order_no` | 本地系统订单号，同时作为插件 `s_order_id`；通过生成器与唯一约束处理碰撞 |
| `merchant_id` + `merchant_order_no` | 未软删订单的幂等键。重复请求比较 `currency` 和原始请求金额 `original_amount`（历史订单为空时回退 `amount`），并非校验所有明细字段一致 |
| `application_id`、`payment_group_id` | 建单应用与支付组 |
| `payment_method_id`、`payment_method` | 渠道 ID 与代码快照。当前渠道代码在未删除渠道中全局唯一；历史解析复用 `paymentMethodConfig()` |
| `designated_payment_method_key` | 记录本次是否显式指定渠道，区别于普通组内路由 |
| `source`、`platform`、`checkout_link_id` | 入口来源（`api`/`manual`/`checkout_link`）、平台类型、可选的收款链接关联 |
| `currency`、`subtotal`、`shipping_fee`、`discount`、`tax`、`amount` | 订单原币种金额；公式容差当前为 0.01，商品小计按两位小数核对 |
| `converted_currency`、`converted_amount`、各 `*_converted` | 下单时折算金额，结算基准 USD；成交额统计使用 `converted_amount` |
| `original_exchange_rate`、`exchange_rate`、`surcharge_*` | 市场汇率、实际汇率与汇损快照；不能用当前汇率回算历史单 |
| `fee_percent_amount`、`fee_fixed_amount`、`settlement_amount` | 下单时交易手续费与净入账快照；支付入账使用 `settlement_amount` |
| `refunded_amount` | 累计退款原币种金额，不是 USD |
| `accept_language` | 浏览器语言偏好列表，nullable VARCHAR(255)；统一建单入口最多保留 255 字符，避免请求头导致建单失败 |
| `shipping_state`、`shipping_city` | 收款链接按 PayPal `admin_area_1` / `admin_area_2` 语义保存：省州按国家转换为官方代码或名称，城市保存城镇名称；服务端与 Google 回填共用映射。其他入口协议保持原有语义，详见[收款链接地址规则](../doc/checkout-link.md#地址字段与-paypal-rest) |
| `pay_url`、`wp_order_id`、`transaction_id` | 远端收银台链接、WordPress 订单 ID、网关交易号。缺少 `pay_url` 的本地单可能需要补建远端 |
| `payment_link_token`、`send_mail`、`payment_link_sent_at` | 订单级付款链接与邮件意图/发送时间，不等同于 `checkout_links.slug` |
| `paid_at`、`failed_at` | 首次支付/失败时间；插件支付时间按 UTC 解析后转系统时区，首次写入后不覆盖 |
| `pending_status_query_count`、`pending_status_last_queried_at` | 定时补查已领取次数（默认 0）和最近领取时间（默认 NULL）；创建满 30/90 分钟各一次，最多两次，调用网关前原子递增，失败也计次；手工查询不占用 |

金额计算应保持十进制字符串/bcmath；数据库金额多为 `decimal`，模型用相应 decimal cast。远端 JSON 或现存风控部分代码有 float 转换，不应据此将账务计算改为浮点运算。

历史漏配汇损的专用纠错入口 `orders:recalculate-surcharge` 是修改汇率快照的显式例外：保留 `original_exchange_rate` 和手续费金额，更新实际汇率、汇损、订单折算字段及净入账；原币金额、原台账均不改。仅处理已入账无退款/争议订单，必须保留命令生成的新旧快照审计报告；余额由人工另记调整流水，历史统计按受影响日期补算。详见[汇损补算](../doc/order-surcharge-recalculation.md)。

商品数据分为四层：

- `order_items`：商户传入的原始明细及折算单价。
- `order_matched_items`：支付站点实际接收的明细、来源变体及自动创建信息。
- `site_products` / `site_product_variations`：按渠道同步的站点商品/变体快照，用于匹配与复制。
- `replace_keywords`：`COPY` 模式所用的商户商品名称替换规则。

`order_matched_items.product_name` 使用非空 `TEXT` 保存站点商品名称快照，避免站点商品名（`site_products.name` 最多 500 字符）或远端创建响应的长名称在建单时超出旧 255 字符列。原始 `order_items` 与 `checkout_link_items` 的名称长度和输入协议保持 255 字符。新增迁移 `2026_10_16_153313` 仅扩容匹配快照名称；如含超过 255 字符的名称（包括软删除记录），回滚会拒绝缩回旧列宽，避免截断历史数据。

### 支付方式进货单（2026-10-19，当前工作区）

`purchase_orders` 是独立的本地进货留档，不参与 `orders` 支付状态、商户余额或成交统计。通过 `payment_method_id` 关联支付方式，`created_by` 保留生成用户；两者的物理删除受外键限制，关系包含软删除记录。`order_no` 固定 19 位（`33` 加 17 位随机数字），`transaction_id` 为下单时间 `YmdHis` 加 20 位随机数字，各有独立唯一约束。

`ordered_at` 可设置；`paid_at` 为下单后 1 秒至 1 小时，`shipped_at` 为付款后 1–2 天，`received_at` 为发货后 3–5 天，均按系统时区保存模拟时间。`currency` 固定 `CNY`，`subtotal` 为商品明细之和，`total = subtotal + shipping_fee - discount`；默认运费/优惠分别为商品合计的 3%–8% / 10%–20%，允许手动编辑非负金额，优惠不超过商品合计。

`purchase_orders.perks_words` 为可空 JSON 数组，`null` 表示尚未初始化（包括迁移前的历史订单），空数组表示已初始化且不展示权益。生成进货单时不选权益词；启用的超级管理员首次打开采购页或详情页时，在事务行锁内从 10 个候选词中随机选取 4–8 个并保存，保持候选原有顺序且不重复。后续访问复用保存的数组，不重新随机；初始化不改变 `updated_at`、金额或商品快照。

`purchase_order_items` 保存商品名称（优先中文翻译）、SKU、USD 原价 `source_price = site_products.price_min`、人民币 `unit_price`、数量和行金额 `subtotal`；单价按 USD 原价 × 7 × 0.3 四舍五入两位小数。金额列为 `decimal(20,2)`，计算使用 bcmath，修改源商品不回写快照。商品物理删除仅将可空 `site_product_id` 置空；进货单物理删除才级联删除其明细，后台不提供删除入口。

采购页面沿用上述快照，图片和供需方资料没有额外保存快照：分别读取 `site_products.image_url` 和支付方式当前档案，商品关联不存在或图片地址不安全时显示占位。编辑运费/优惠后重新打开或刷新采购页面会显示最新金额；页面首次读取只初始化权益词。

采购订单详情的收货人/手机号使用档案 `legal_representative_name` / `legal_representative_phone`，收货地址取 `stock_addresses` 字符串数组中首个非空地址（无可用值时回退 `company_address`）。卖家联系人与供货商名称分别使用 `supplier_contact_person`、`supplier_name`，其他卖家资料同样读取当前档案；这些字段不是进货时的快照，修改档案后页面会变化。采购单尚无物流公司和运单号字段，详情页不从模拟时间推导物流单号。

### 应用自动折扣（2026-09-30，当前工作区）

`applications.is_auto_discount_enabled` 默认关闭，仅影响新订单。按折扣前应付金额、订单原币种计算：不超过 200 随机减 0.01～0.10，超过 200 随机减 0.01～0.50，以 0.01 为步长。极小金额缩小随机范围，至少保留 0.01 应付；金额不超过 0.01 时跳过。

`orders.original_amount` 保存折扣前请求应付金额供幂等比较，历史订单为 NULL 时回退 `amount`；`auto_discount` 保存本次自动减免，默认 0，已包含在 `discount` 中。先验证请求公式和原始商品，再增加折扣、减少 `amount`，随后计算汇率、风控、手续费与结算快照。原始商品不变，商品匹配溢出折扣仍可叠加；补单与开关变化不重新抽取自动折扣。查询、通知与远端支付使用折扣后实际金额。

### 自动匹配商品的订单折扣（2026-09-30）

MATCH / VIRTUAL 的商品保留站点原价（折算为订单币种的两位小数单价）。尾件超额加入订单 `subtotal` 与 `discount`，同步更新两者的 USD 折算字段，`amount`、`converted_amount` 和结算金额不变。商户原始 `order_items` 保持原样；匹配后的 `subtotal` 对应 `order_matched_items`，因此可能不再等于原始明细之和。

补单按原始明细之和恢复目标小计，并从当前折扣扣除上一轮小计增量，再加入本轮超额，避免重试重复抵扣。新匹配结果将旧的独立 `matched_discount` 归零，网关 `discount_fee` 只发送 `discount`。不批量修改历史订单。

## 4. 状态、资金与审计

`Order::NEVER_PAID_STATUSES = ['pending', 'failed', 'cancelled', 'expired']`。`paidEver()` 取其补集，包含发货、完成、部分退款、退款、拒付、网关争议和人工争议审核状态。新增状态会影响风控和统计，必须同步审查这个口径。

主动查询网关返回 `paid` 时可以纠正任意本地状态，仍保留首次 `paid_at`、`failed_at`、`refunded_amount` 和退款/拒付台账；人工审核事件及其冻结金额也独立保留。曾收款状态恢复 `paid` 不重放首次支付入账；已扣拒付本金的订单按流水阻止再次退款或拒付。详见 [D-17](decisions.md#d-17-主动查询已支付结果优先于本地状态)。

定时补查使用 `orders_pending_status_sync_index(status, paid_at, pending_status_query_count, created_at)` 支持全平台到期扫描。新增迁移只添加补查字段及索引，历史查询没有可恢复的次数记录，既有订单从 0 开始累计，仍限最近 48 小时；不会按历史日志臆测已执行次数。回滚会删除补查计数和时间，生产优先前向修复，避免重新开放已消耗的补查机会。详见 [D-18](decisions.md#d-18-待支付订单按创建时间最多自动补查两次)。

| 表 / 模型 | 关键语义 |
|---|---|
| `merchant_balance_transactions` | 总余额变动台账，含前后余额、金额、类型、关联单据与 `idempotency_key`；自动支付入账键为 `order_paid:{order_id}` |
| `merchant_withdrawals` | `pending` 冻结金额；批准扣总余额并释放占用，驳回仅释放冻结额 |
| `merchant_fund_freezes` | 人工资金冻结及可选到期释放；与提现/争议冻结分别记录来源，共同影响 `frozen_balance` |
| `order_refunds` | 退款单：原币种 `amount`、USD `amount_usd` 等；同一订单允许多次部分退款 |
| `order_dispute_events` | 人工审核事件，`processing`/`closed`、冻结金额快照、到期与关闭原因。`final_action` 是拟定方向，不自行退款/拒付 |
| `order_dispute_event_replies` | 回复、操作人及凭证附件；不延长原审核期限 |
| `order_shippings` | 每订单最多一条未删除物流记录，另有向插件同步的状态/错误信息 |
| `order_events` | 插件事件归档，按系统订单号与外部日志 ID 去重，不驱动订单状态 |
| `order_notification_attempts` | 商户回调每次尝试的请求、响应、结果、下次重试时间 |
| `ad_conversion_attempts` | 按订单/广告平台/尝试编号记录转化发送与重试 |
| `logistics_import_tasks`、`logistics_import_task_records` | 批量物流导入任务及逐行结果 |

余额可为负；可用余额 = `balance - frozen_balance`，提现不得超过可用额。冻结/释放通常改变冻结额并维护对应业务单据，不代表每次冻结都发生总余额增减。金融审计表不能套用普通配置表的删除策略。

## 5. 收款链接与域名

| 表 | 语义 |
|---|---|
| `merchant_domains` | 商户域名、Cloudflare 自定义 hostname/证书状态，未删除 `host` 全局唯一 |
| `checkout_links` | 商户可重复使用的收款配置；关联应用、支付组及可选商户域名，支持固定/范围金额、支持国家、说明等；`auto_redirect` 默认 true，控制建单后自动跳转或展示付款链接 |
| `checkout_links` | 商户可重复使用的收款配置；关联应用、支付组及可选商户域名，支持固定/范围金额、支持国家、说明等。`link_name` 是商户**内部标识**（后台列表/搜索用，不展示给客户），与对客展示的 `title` 分工不同，两者均必填 |
| `checkout_link_items` | 链接预设商品；实际下单时转换为 `order_items` |
| `orders.checkout_link_id` | 订单来源追踪；一个链接可以产生多笔订单 |

链接的 `slug` 在未删除记录中唯一；每次公开下单生成新的商户订单号，不能把 slug 或客户邮箱作为跨提交固定幂等键。自有域名是否可用由 `MerchantDomain::isReady()` 和 `CheckoutLink::allowedHosts()` 配合判断。

## 6. 汇率与统计派生数据

- `exchange_rates`：当前汇率，`base_currency + target_currency` 唯一；下单汇率数据源。
- `exchange_rate_histories`：追加式历史展示数据，不参与下单金额计算。
- `order_daily_stats`：按 `stat_date + merchant_id + application_id + payment_method_id` 唯一；渠道 ID `0` 表示未知维度，刻意不设渠道外键，避免删除渠道改写汇总维度。
- `order_stats_runs`：每个统计日的执行完成记录；`rows_written=0` 表示已算但无交易，与未运行区分。

| 指标 | 归日时间 | 金额口径 |
|---|---|---|
| 支付成功 | `orders.paid_at`，旧数据为空时回退 `created_at` | `converted_amount`，含曾支付成功的后续状态 |
| 支付失败 | `orders.failed_at`，旧数据为空时回退 `created_at` | `converted_amount`，只数 `failed` |
| 退款 | `order_refunds.created_at` | 实退 `amount_usd`，当日订单数去重 |
| 拒付 | 拒付本金流水 `created_at` | 本金流水金额绝对值，不含拒付手续费 |

按系统时区分日；商户展示时区不改变分桶。汇总可重算，每日事务内删除旧桶再插入新桶，随后记录执行结果；超出滚动窗口的历史修正需按受影响日期补算。

## 7. 唯一约束与迁移

软删除表使用生成列：有效记录映射原值，删除记录映射 NULL，再建唯一索引。不要简化为 `(业务键, deleted_at)`；MySQL 对 NULL 的唯一性处理不能达到同样效果。

| 约束 | 当前含义 |
|---|---|
| `orders.order_no_uniq`、`payment_link_token_uniq` | 未删除订单的系统号/付款 token 唯一 |
| `orders(merchant_id, merchant_order_no_uniq)` | 商户内有效订单幂等 |
| `payment_methods.method_code_uniq` | 有效渠道代码全局唯一，已由 2026-09-21 迁移替换早期商户内唯一规则 |
| `applications.app_id_uniq` | 有效应用 ID 全局唯一 |
| `payment_groups(merchant_id, group_key_uniq)` | 商户内有效支付组键唯一 |
| `order_shippings.order_id_uniq` | 每订单仅一条有效物流记录 |
| `order_dispute_events.order_id_active_uniq` | 仅 `processing` 状态参与，同一订单最多一个处理中审核事件 |
| `merchant_balance_transactions.idempotency_key` | 自动资金动作幂等；允许人工动作无幂等键 |
| `merchant_payment_methods(merchant_id, payment_method_id)` | 渠道分配不重复 |

迁移存在 `IF`、`CONCAT`、带排序的 `GROUP_CONCAT` 等 MySQL 特征；`phpunit.xml` 目前仍使用 SQLite 内存库，测试兼容性需单独核验。不要仅根据初始建表迁移推断最终约束。

新增数据结构同时检查：模型 fillable/casts/关系、权限与租户范围、现有数据回填、索引、删除策略、相关 FormRequest/Resource 和测试。种子顺序见 `DatabaseSeeder`，商户角色创建前必须已存在权限记录。

`Application::api_key` 及部分邮件/广告凭证依赖 Laravel 加密 cast；沿用原 `APP_KEY` 才能读取已有数据。文档与测试样例只用占位凭证。

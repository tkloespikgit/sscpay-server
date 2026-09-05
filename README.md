# 多商户跨境收款订单系统（Order System）

面向跨境电商场景的**多商户收款中台**：商户的电商站点（WordPress / WooCommerce、Shopyy、Shopline、OpenCart、手工发票等）通过签名 API 下单，系统完成汇率换算、支付渠道路由与风控、远程建单、托管收银页、状态回调、资金入账、物流回传与商户通知的全链路编排；平台方统一管理商户、支付渠道、汇率、系统配置与资金审核，平台侧账号分**超级管理员**（全平台不受限）与**商户级管理员**（只能管理自己名下的商户及其业务数据）两级。

后台基于 Filament v5，一套代码同时服务平台侧账号（超级管理员 / 商户级管理员）与多个商户自己的用户，数据通过 `merchant_id` 做行级隔离。

---

## 目录

- [1. 核心业务流程](#1-核心业务流程)
- [2. 技术栈](#2-技术栈)
- [3. 架构要点](#3-架构要点)
- [4. 功能模块](#4-功能模块)
- [5. 订单状态机](#5-订单状态机)
- [6. 对外接口](#6-对外接口)
- [7. 权限体系](#7-权限体系)
- [8. 系统配置项](#8-系统配置项)
- [9. 异步任务与定时调度](#9-异步任务与定时调度)
- [10. 目录结构](#10-目录结构)
- [11. 环境要求与部署](#11-环境要求与部署)
- [12. 已知限制与注意事项](#12-已知限制与注意事项)
- [13. 文档索引](#13-文档索引)

---

## 1. 核心业务流程

```
商户电商站点（WordPress 插件 / 自建系统）              本系统                        WordPress 支付网关聚合插件（PGA）
──────────────────────────────────          ──────────────────────              ──────────────────────────────
1. 结账 → POST /api/order/create  ────────►  验签 → 幂等检查 → 金额校验
                                             → 回跳域名一致性校验
                                             → 汇率 + 汇损快照（折算 USD）
                                             → 支付组内加权路由 + 风控阈值筛选
                                             → 锁定唯一支付方式
                                             → 落库订单 + 商品明细
                                             → 商品自动匹配 / 建站创建（可选）
                                             → POST /pay 远程建单   ────────────►  返回收银台 pay_url
   ◄───────────────────────────────────────  返回 order_no + payment_url

2. 引导客户打开托管收银页 /payment/{token}
   （手工建单场景：邮件发送付款链接）

3. 客户完成支付
                                             ◄──── POST /api/webhooks/...  ──────  payment_status 回调（X-PGA-Signature）
                                             验签 → 状态守卫 → 更新订单状态
                                             → 入账商户余额（USD）
                                             → 通知商户 notify_url（5 次退避重试）
                                             → Telegram 提醒
                                             → 拉取 /order-logs 归档订单日志

4. 商户发货 → POST /api/order/ship
                                             写物流记录 → 订单 paid 自动转 shipped
                                             → POST /sync-tracking  ───────────►  回传物流给支付渠道

5. 兜底：POST /api/order/query 主动查询
```

**核心原则**：订单状态以 webhook 回调 + 主动查询为准，`return_url` 跳转只是用户体验，不可信。

---

## 2. 技术栈

| 分类 | 选型 |
|---|---|
| 语言 / 框架 | PHP **8.2+**（必需 `ext-bcmath`）、Laravel **12** |
| 后台面板 | Filament **v5**（含 App Authentication TOTP 多因素认证） |
| 权限 | `spatie/laravel-permission` v6（角色按 `roles.merchant_id` 归属商户，未启用官方 teams 模式；`merchant_id = NULL` 表示平台级角色，目前只有一个全平台共享的"商户级管理员"角色用到这个位置） |
| 数据库 | **MySQL 8.0**（迁移大量使用 `virtualAs('IF(...)')` 虚拟生成列做软删除安全的唯一约束，**不兼容 SQLite**） |
| 缓存 / 会话 / 队列 | Redis |
| 对象存储 | 阿里云 OSS（`alphasnow/aliyun-oss-laravel`）——数据库备份、争议附件转存 |
| 前端构建 | Vite + Tailwind CSS |
| 日志查看 | `achyutn/filament-log-viewer`（后台内嵌，仅超管可见） |
| 外部依赖 | WordPress「支付网关聚合插件」（`/gateway-config` `/pay` `/sync-tracking` `/order-query` `/order-logs` `/health`）、汇率 API、百度智能云机器翻译、SMTP 邮件、Telegram Bot API |

---

## 3. 架构要点

### 3.1 多租户隔离

单库共享 schema，所有业务表带 `merchant_id`：

- `App\Models\Concerns\BelongsToMerchant` trait：自动注册全局 Scope、创建时回填 `merchant_id`（仅对绑定单一商户的普通商户用户生效）、提供 `scopeForMerchant()`。
- `App\Models\Scopes\MerchantScope`：**仅在有登录后台用户时生效**，按该用户的 `App\Models\User::manageableMerchantIds()` 过滤——超级管理员返回 `null` 表示不受限，商户级管理员返回其 `ownedMerchants()` 的 ID 集合，普通商户用户返回自己的 `merchant_id`。这一处改动会自动传导到所有用 `BelongsToMerchant` 的模型（Order / Application / PaymentMethod / PaymentGroup / MerchantWithdrawal / MerchantBalanceTransaction / OrderDisputeEvent / TelegramBot 等），无需逐个模型适配。

系统存在三条请求路径，隔离方式不同，改动代码时务必区分：

| 路径 | 租户来源 | 隔离手段 |
|---|---|---|
| Filament 后台 | `auth()->user()->merchant_id` | `MerchantScope` 自动生效 |
| 对外 API | `ApiAuthentication` 验签后由 App-ID 反查注入 `$request->attributes` | **必须**显式 `forMerchant($merchantId)` |
| 队列 / Artisan 命令 | 无登录用户 | **必须**显式 `forMerchant()` 或 `where('merchant_id', …)` |

> ⚠️ 在 API / 队列 / 命令上下文里 `MerchantScope` 不会介入。`withoutGlobalScopes()` 之后**必须**自己补 `merchant_id` 条件，否则会跨商户读写数据。

### 3.2 平台侧账号：超级管理员与商户级管理员

平台侧账号统一特征是 `merchant_id` 为 `NULL`（不绑定单一商户），按 `is_super_admin` 分两级，`App\Models\User::isPlatformStaff()` 判断"是否属于这两级之一"：

- **超级管理员**（`is_super_admin = true`）：`AppServiceProvider` 里用 `Gate::before()` 短路所有权限判断，不受任何限制——管理全平台商户、全部管理员账号（含商户级管理员）、以及系统配置 / 承运商 / 汇率 / 日志查看器等纯平台级基础设施。只能通过 `php artisan make:super-admin` 创建（**不要用 `make:filament-user`**，它不认识 `is_super_admin` 字段，建出来的账号登录后什么都看不到）。
- **商户级管理员**（`is_super_admin = false` 且 `merchant_id` 为 `NULL`，`User::isMerchantManager()` 判断）：只能管理**自己名下**（`merchants.owner_id` 指向自己，一对多）的商户及其全部业务数据——订单、支付方式、支付组、应用、提现、余额流水、争议、Telegram 机器人配置，也能在名下商户创建/管理用户账号；看不到其他商户级管理员或超管账号，也碰不到系统配置 / 承运商库 / 汇率等纯平台基础设施。可以自行创建新商户，创建时自动把 `owner_id` 落成自己。持有全平台共享的一个 Spatie 角色（`roles.merchant_id = NULL`，名字固定为"商户级管理员"），权限集为全部商户级权限 + `merchants.manage`（`App\Support\Permissions::platformMerchantManager()`），由 `App\Services\PlatformRoleProvisioningService` 幂等 provision（`PermissionSeeder` 会调用一次）。通过后台"用户管理"里的"设为商户级管理员"开关创建，或 `php artisan make:merchant-manager`。

`User::manageableMerchantIds()` 是唯一的判断入口：超管返回 `null`（不限），商户级管理员返回 `ownedMerchants()->pluck('id')`，普通商户用户返回 `[$this->merchant_id]`。`MerchantScope`、`MerchantResource` / `UserResource` 的 `getEloquentQuery()`、以及各 Resource 表单里的商户选择器都统一调这个方法做行级隔离，不需要在每处各写一遍判断。

### 3.3 资金单一入口

所有会改动商户余额 / 冻结余额的操作**必须**经过 `App\Services\BalanceService`，它统一保证四件事：

1. **事务 + 行锁**：`DB::transaction` 内 `lockForUpdate()` 锁商户行，避免并发退款竞态；
2. **幂等**：自动入账用 `idempotency_key` 唯一约束兜底；
3. **台账**：每次余额增减落一条 `merchant_balance_transactions`；
4. **精度**：记账口径统一 USD，全程 bcmath 字符串运算。余额允许为负，但提现不能超过可用余额。

后台资金类写操作额外经过 `App\Filament\Support\FinanceSecurity` 做 **step-up 2FA**：操作者必须已绑定身份验证器，且现场输入一次性 TOTP 验证码（不可重复使用）。

### 3.4 事件驱动

`OrderStatusChanged` / `LogisticsImportCompleted` / `OrderEventsSyncCompleted` 三个事件，`SendTelegramNotification` 监听器异步（`ShouldQueue`）推送商户 Telegram。商户未配置或未启用 Bot 时静默跳过，不回退系统默认 Token。

---

## 4. 功能模块

### 4.1 平台管理

商户管理向超级管理员和商户级管理员开放（后者范围限定在自己名下的商户，见 [3.2](#32-平台侧账号超级管理员与商户级管理员)）；系统配置、支付类型配置模板、承运商管理、日志查看仍为**超级管理员专属**。

| 模块 | 说明 |
|---|---|
| **商户管理** | 商户基本信息、联系人、启用状态、余额 / 冻结余额、备注、`owner_id`（所属商户级管理员，仅超管可见可改，留空表示平台直管）。新建商户时 `MerchantObserver` 自动开通 5 个默认角色；商户级管理员创建商户时 `owner_id` 自动落成自己，只能编辑/查看自己名下的商户，不能删除（删除始终仅超管） |
| **系统配置** | 白名单化的 `config_key`（不允许在后台随手加野生 key），按 `value_type`（string / number / json / boolean / image）渲染对应控件，读取带 1 小时缓存，写入时同步清缓存 |
| **支付类型配置模板** | 全平台共享的 Stripe / PayPal / Airwallex 等网关配置模板（含 `payment_config_tag`），商户建支付方式时套用模板再填各自的 `config` 值 |
| **承运商管理** | 系统支持的物流承运商清单，`CarrierSeeder` 预置 **1088** 条。API 发货、手工录入、CSV 批量导入三个入口都校验 `carrier_code` 必须在此表内 |
| **日志查看** | 后台内嵌 Laravel 日志查看器 |

### 4.2 商户配置

| 模块 | 说明 |
|---|---|
| **应用管理** | 商户的接入应用：`app_id` + `api_key`（`encrypted` cast 加密存储）、绑定网站、订单邮件开关、发件人邮箱 / 名称、启用状态。列表支持商户筛选（超管可见）、App ID 模糊搜索、**一键复制**（自动处理 `app_id` 冲突） |
| **用户与角色** | 用户挂 `merchant_id`，分配该商户名下的角色；支持界面语言 `language_code`、MFA 绑定。角色由商户自建，权限项从平台统一定义的清单里勾选。超级管理员在这里还能勾选"设为超级管理员"/"设为商户级管理员"两个开关创建平台侧账号（互斥，均只对超管可见）；商户级管理员登录后看到的是自己名下商户的用户列表，创建用户时只能从名下商户里选 |

商户新建时自动开通 5 个默认角色：**商户管理员**（全部商户级权限）、**订单管理员**、**物流管理员**、**网站应用管理员**、**财务管理员**。`MerchantRoleProvisioningService` 用 `firstOrCreate` + `syncPermissions` 实现，可反复执行。

### 4.3 支付渠道配置

| 模块 | 说明 |
|---|---|
| **支付方式** | 每个支付方式对应一个 WordPress 收款站点，配置项包括：<br>· 基本：`method_code` / `method_name` / 启用 / 排序权重<br>· 站点凭证：`domain`、WooCommerce REST 密钥（`domain_client_id` / `domain_client_sk`）<br>· 网关认证：所有插件接口（下单 / 物流 / 网关配置 / 查询 / 日志）**统一用 WooCommerce REST 密钥做 Basic Auth**（原 `order_account` / `order_password` / `config_account` / `config_password` 四个应用密码字段已弃用）、`payment_config_id`<br>· 商品策略：`product_match_mode`（MATCH / CREATE / VIRTUAL）、`invoice_prefix`、`virtual_product_prefix`<br>· 行为开关：`sync_logistics`（是否回传物流）、`allow_returned_source`（是否允许返回源站）<br>· **风控阈值**：单笔金额上限、当日金额上限、当日笔数上限、当月金额上限（`0` = 不限制）<br>· **手续费**：退款手续费 `refund_fee`、拒付手续费 `chargeback_fee`<br>表单采用左右分栏（左：基本信息；右：网关配置 / 风控阈值 / 手续费），列表支持复制 |
| **支付组** | `group_key`（下单时商户传入）+ **统计时区**（决定"当日 / 当月"窗口，与风控阈值共用同一时区口径）+ 组内支付方式及各自的**进单占比**（pivot `priority`，数值越大占比越高） |

**路由策略**：下单时**直接锁定唯一支付方式**，不返回候选列表。算法为**加权均匀分配**——在通过风控的候选中，选择「当日已成交金额 ÷ 权重」最小的通道（平局时权重大者优先，再按 id 升序保证确定性），长期收敛到配置的占比，避免单通道先打满日限额后流量断崖切换。全部候选被风控拦截则整单失败（`NO_AVAILABLE_PAYMENT_METHOD`，不创建订单）。

### 4.4 订单

| 能力 | 说明 |
|---|---|
| **API 下单** | 见 [6. 对外接口](#6-对外接口)。`merchant_order_no` 为幂等键（商户维度唯一），重复提交原样返回已有订单，不重算汇率、不重走风控。并发请求用 `order-create:{merchant_id}:{merchant_order_no}` 分布式锁串行化 |
| **手工建单** | 后台自定义页面，复用同一套 `OrderCreationService`（`source = 'manual'`），额外触发付款链接邮件；超管需先选择归属商户 |
| **订单列表** | 筛选器常驻表格上方（`FiltersLayout::AboveContent`）：商户（超管可见）、应用、支付方式、状态、发货状态（已发货 / 未发货）、物流同步状态、日期区间等；表格上方展示**本次查询的多币种金额统计条**；商户名等敏感列仅超管可见 |
| **订单详情** | 完整 infolist + 5 个 RelationManager（商品明细、自动匹配明细、订单日志、通知尝试记录、争议审核事件）+ 行内动作：查询最新状态（主动调插件 `/order-query`）、**同步订单事件**（主动调插件 `/order-logs` 补齐时间线）、手动同步物流、退款、拒付、重发付款链接等 |
| **订单日志** | 由 `order-events:sync` 逐笔调插件 `/order-logs` 拉取归档（下单、发起支付、回调、争议、重试等人工可读日志），按 `(order_no, external_log_id)` 幂等写入。订单详情页的「同步订单事件」按钮走同一套逻辑（`OrderEventSyncService::syncOrderNow()`），用于时间线没跟上时立刻补拉，不用等下一轮调度。**只做归档，不驱动状态流转** |
| **争议审核事件** | 财务管理员对**已付款**订单开立争议 → 冻结该笔金额、订单转 `dispute_review`（同一订单同时只能有一条处理中事件）→ 订单管理员回复补充材料（富文本经 XSS 过滤、图片转存 OSS）→ 人工手动结束或到期自动结束（释放冻结资金、订单回退 `paid`，`close_type` 区分 manual / auto）；24 小时内到期的事件通过 Telegram 提醒。与网关 webhook 推的 `disputing` 是两套独立机制 |
| **退款 / 拒付** | `BalanceService::refund()`（支持部分退款，累计到 `refunded_amount`，状态转 `partially_refunded` / `refunded`）、`chargeback()`；按支付方式配置的 `refund_fee` / `chargeback_fee` 扣手续费。**两者都要求人工操作 + step-up 2FA**，webhook 收到 `refunded` / `chargeback` 状态时只更新订单、拉日志、发 Telegram 提醒，**不自动扣钱** |

### 4.5 资金管理

| 模块 | 说明 |
|---|---|
| **商户余额** | `merchants.balance`（总余额）+ `frozen_balance`（审核中提现 / 争议冻结占用），可用余额 = 两者之差。订单支付成功自动入账（USD，含幂等键） |
| **余额流水** | 每一笔余额变动一条台账记录（类型、金额、关联单据、操作人、幂等键），只读 |
| **提现** | 商户发起申请（`pending`，冻结对应金额）→ 审核放款（`approved`，扣减总余额与冻结额）或驳回（`rejected`，仅释放冻结）。申请与审核均需 step-up 2FA |
| **人工调账** | 超管 / 商户财务管理员手工增减余额，必填原因，落台账 |

### 4.6 物流

| 能力 | 说明 |
|---|---|
| **API 发货** | `POST /api/order/ship`，`carrier_code` 必须在承运商表内 |
| **手工录入** | 允许录入的订单状态：待支付（预录入，不改状态）、待发货（自动推进为已发货）、已发货（补发 / 改单）、部分退款、争议中；终态订单不允许。同一订单已有记录时按 `updateOrCreate` 覆盖 |
| **状态自动机** | `OrderShippingObserver` 是唯一驱动源：仅 `paid` / `shipped` 状态的订单会被推进为 `shipped`，其余状态只保存物流记录 |
| **批量导入** | 导出模板 → 商户填写 → 上传 OSS → `ProcessLogisticsImportJob` 异步处理 → `LogisticsImportTask` 记录进度与结果，完成后 fire `LogisticsImportCompleted` |
| **回传渠道** | 每次物流落库自动 dispatch `SyncOrderTrackingJob` 调插件 `/sync-tracking`；`sync_status` 记 pending / synced / failed，失败原因写 `sync_message`，后台可**手动重新同步**。Job 只尝试一次（失败原因多为配置问题，自动重试无意义） |

### 4.7 站点商品同步与订单商品自动匹配

**商品同步**（`WooCommerceProductSyncService`）：用支付方式上配置的站点凭证调插件扩展端点 `/wp-json/wc/v3/products-with-variations`，一次请求内联返回商品及其变体，连同名称的中文翻译（百度智能云 `texttrans`，限频 1 QPS）upsert 到 `site_products`，变体逐条写入 `site_product_variations`（简单商品把主商品自身作为唯一变体写入）；远端已删除的商品连同变体一起清理。金额币种统一 USD，整体幂等，可由队列重试（`tries = 3`）。

> ⚠️ 该自定义端点**不返回** `X-WP-Total` / `X-WP-TotalPages` 分页头，翻页必须持续请求直到空页并以页数上限兜底，否则只会拉到第一页且清理逻辑会误删其余本地商品。

**商品匹配**（`OrderItemService`）——下单时按支付方式配置的 `product_match_mode` 分流，另外**回跳地址与站点同域名时自动走"同站点直连"**（不做匹配，直接用商户传入明细）：

| 模式 | 行为 |
|---|---|
| `MATCH` / `VIRTUAL` | 从站点商品变体中**贪心凑单**：目标金额折算 USD 后，每轮从"单价 ≤ 剩余额度"的变体里取最高价前 10 随机选 1；额度买不起任何一件时**末件改价补齐**（优先挑原价 ≥ 剩余额度且最接近的变体小幅打折，永不涨价）；折扣深度低于 `order_match.min_price_ratio`（默认 0.4）时回溯退掉上一行一件凑大额度，最多 3 轮；单个变体件数上限每次在 `1 ~ order_match.max_item_quantity`（默认 3，0 = 不限）之间**随机**确定；商品池按临界值算出的总容量不够打满目标金额时直接报"容量不足"。匹配结果写入 `order_matched_items`，小计与目标金额分毫不差 |
| `CREATE` | 逐条按商户明细的 USD 折算价在站点变体中找**同价商品**（允许 ≤5% 汇率差额）；找不到则取"价格更高且最接近"的变体作模板，复制改价并**在 WordPress 站点上同步创建同价商品**（变体商品的复制体一律作为简单商品）。因远程建单需要真实商品 ID / 链接，创建必须在下单时同步完成，不走队列 |

### 4.8 通知

| 通道 | 说明 |
|---|---|
| **交易结果回调** | 订单状态变化时 POST JSON 到商户下单传入的 `notify_url`，payload 带 `sign`（`api_key` 做 HMAC-SHA256，顶层 ksort）。最多 **5 次**尝试，间隔 30 秒 → 5 分钟 → 30 分钟 → 1 小时（可后台配置）。采用**惰性创建下一行**策略：失败后当前行置 `failed` 并写 `next_retry_at`，由调度任务扫描到期记录再生成下一次尝试。每次尝试完整记录请求头与响应（超长按配置截断） |
| **付款链接邮件** | 手工建单后 `SendPaymentLinkJob` 发送 `PaymentLinkMail`，发件人取应用配置的 `sender_email` / `sender_name`，链接有效期默认 7 天（可配置） |
| **Telegram** | 每商户最多绑定一个 Bot（单例设置页，不是列表 Resource），支持发送测试消息。商户未配置 / 未启用时直接跳过。触发点：订单状态变更、争议开立 / 到期提醒、退款 / 拒付等需人工介入的场景 |

### 4.9 仪表盘

- **概览卡片**：总订单数、支付成功订单数、成交额等（按角色动态 4 / 5 列布局）
- **支付成功率**（口径 1，订单维度）：分母 = 时间窗内**已到达终态**的订单（排除 `pending`），分子 = 其中**曾支付成功**的订单（含 `shipped` / `completed` / `refunded` / `partially_refunded` / `chargeback`）。支持时间窗（7 / 30 / 90 天）与支付方式筛选实时联动，按区间着色（≥90% 绿、70~90% 黄、<70% 红）
- **订单趋势图**：按日订单数与成交额（USD）
- **支付方式占比**：已支付订单按渠道分布
- **商户销售排行榜**：跨商户数据，**仅超管可见**（不下放给商户级管理员——它本质是平台视角的横向对比）

商户用户只能看到自己商户的数据；超管看到全平台汇总；商户级管理员看到的是名下商户范围内的汇总（底层统计查询走的是 `Order` 等模型，自动继承 `MerchantScope` 的 `manageableMerchantIds()` 过滤，仪表盘代码本身不需要感知这三层区别）。

### 4.10 汇率

`exchange:fetch` 每小时按 `exchange.supported_currencies` 配置的币种列表拉取实时汇率，批量更新 `exchange_rates`，并向 `exchange_rate_histories` 追加一批快照。下单时锁定**汇率快照**：`original_exchange_rate`（市场价）→ 按 `surcharge_type` 计算汇损（`percent` 百分比 / `fixed` 固定值）→ `exchange_rate`（实际结算汇率），换算出 USD 金额与 `surcharge_fee`，全部落在订单上，历史数据不随系统汇率变动。

两张汇率表分工明确：`exchange_rates` 有 `(base_currency, target_currency)` 唯一约束、每次抓取原地覆盖，**只保留当前值**，是下单链路的资金数据源；`exchange_rate_histories` 是追加式（append-only）历史序列，**只服务于后台展示，不参与任何金额计算**。快照写入包在独立 try/catch 里，失败只影响趋势图、不影响 `exchange_rates` 更新。保留期由 `exchange.history_retention_days` 控制（默认 120 天，0 或负数表示不清理），命令每轮自动清理过期快照。

后台「平台管理 → 汇率趋势」（`/admin/exchange-rate-trends`，**仅超级管理员**）展示：最后一次同步时间与距今、已配置抓取币种、快照条数与保留期、`exchange_rates` 当前值，以及各币种兑 USD 的**日均汇率折线图**（可切 7 / 30 / 90 天窗口）。页面右上角「立即同步汇率」在 Web 请求内同步执行 `exchange:fetch`，以「快照表最后同步时间是否前进」判定成败，完成后硬刷新整页。基准币种自身（USD/USD 恒为 1.0）在绘图层被排除。

> 历史快照从该表上线那一刻起按小时累积，第三方免费版接口不提供历史序列、订单表里的 `original_exchange_rate` 口径带汇损且样本稀疏，因此不做回填——初期 7/30/90 天视图点位稀疏属预期。

### 4.11 安全

- **API 鉴权**：`App-ID` + `Timestamp`（±5 分钟）+ `X-Nonce`（5 分钟内不可重复，防重放）三件套 Header，签名放请求体 `sign` 字段。规范化算法：移除 `sign` → 关联数组递归 ksort（数字索引列表保持原序）→ `json_encode(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` → 三段式 StringToSign 做 HMAC-SHA256
- **Webhook 验签**：网关回调走独立的 `X-PGA-Signature`（预共享 `PGA_WEBHOOK_SECRET`），不套用 API 鉴权中间件
- **回跳域名一致性**：三个回跳地址（`notify_url`/`return_url`/`cancel_url`）的域名必须与本次下单所用应用绑定的网站域名（`applications.website`）一致，不一致直接拒单（`CALLBACK_DOMAIN_NOT_ALLOWED`），应用未绑定域名时传任一非空回跳地址也会被拒；指定 `payment_method_key` 时还额外要求与该渠道绑定站点域名一致（两者均忽略大小写、`www.` 前缀与端口）
- **后台 MFA**：Filament App Authentication（TOTP），支持恢复码；资金操作再叠加一次性验证码（见 3.3）
- **富文本过滤**：争议理由等富文本经 `RichTextSanitizer` 做 XSS 过滤；附件转存 OSS

---

## 5. 订单状态机

| 状态 | 含义 | 来源 |
|---|---|---|
| `pending` | 待支付 | 创建订单 |
| `paid` | 已支付 | 网关 webhook / 主动查询 |
| `shipped` | 已发货 | 物流记录写入触发（`OrderShippingObserver`） |
| `completed` | 已完成 | 网关回调 |
| `cancelled` | 已取消 | 网关回调 |
| `failed` | 支付失败 | 网关回调 |
| `expired` | 付款链接过期 | 仅主动查询时判定（webhook 不推此状态） |
| `disputing` | 争议中（**渠道侧**） | 网关回调，发 Telegram 提醒人工介入 |
| `dispute_review` | 争议审核中（**平台侧人工**） | 财务开立争议事件，与 `disputing` 互不复用 |
| `partially_refunded` / `refunded` | 部分 / 全额退款 | 人工退款操作 |
| `chargeback` | 拒付 | 人工拒付操作 |

**状态守卫**（`OrderPaymentStatusService`）：

- 终态订单（`failed` / `cancelled` / `expired` / `refunded` / `chargeback` / `completed`）不再接受任何状态覆盖；
- 已收过款的订单族（`paid` 及其后续流转状态）不允许被 `pending` / `failed` / `cancelled` / `expired` 往回改——出现这类 payload 大概率是乱序投递或插件异常，只记警告日志不落库；
- 人工争议状态 `dispute_review` 受专门守卫保护，不被 webhook 覆盖；
- **幂等**：用"目标状态是否等于当前状态"判断重复投递，命中则跳过，不重复入账 / 通知 / 发 Telegram。

---

## 6. 对外接口

### 6.1 商户 API（`api.auth` 中间件：App-ID + 签名）

| 方法 & 路径 | 说明 |
|---|---|
| `POST /api/order/create` | 创建订单，返回 `order_no` / `payment_url` / 锁定的 `payment_method` / 汇率与汇损明细 |
| `POST /api/order/query` | 查询订单状态（GET 语义但需带 JSON body 参与签名） |
| `POST /api/order/ship` | 同步物流信息 |

下单请求关键字段：`merchant_order_no`（幂等键）、`platform`（枚举由系统配置 `order.platforms` 维护）、`currency`、`group_key`（支付组，必填）、`payment_method_key`（可选，指定渠道）、金额五项（`subtotal` / `shipping_fee` / `discount` / `tax` / `amount`）、`customer.*`、`shipping_address.*`、`items[]`（含 `product_id` / `product_url`）、三个回跳地址、`sign`。

**指定 `payment_method_key` 的行为变化**：跳过支付组路由与限额风控，直接用该渠道；代价是三个回跳地址全部必填且域名必须与该渠道绑定站点一致。适用于"下单请求就来自该渠道绑定的站点本身"的同站点直连场景。

### 6.2 错误码

| HTTP | `error_code` | 触发条件 |
|---|---|---|
| 401 | — | 鉴权参数缺失、时间戳过期、Nonce 重复、App-ID 无效 / 禁用、签名错误 |
| 422 | （标准校验错误） | 字段格式问题 |
| 422 | `AMOUNT_MISMATCH` | `amount` 与 `subtotal + shipping_fee - discount + tax` 差 > 0.01 |
| 422 | `ITEMS_SUBTOTAL_MISMATCH` | `subtotal` 与明细之和不符 |
| 422 | `CALLBACK_DOMAIN_NOT_ALLOWED` | 回跳地址域名与下单应用绑定域名（`applications.website`）不一致 |
| 422 | `PAYMENT_METHOD_NOT_AVAILABLE` | 指定的 `payment_method_key` 不存在或已停用 |
| 422 | `PAYMENT_METHOD_DOMAIN_MISMATCH` | 指定渠道时回跳地址缺失或域名与渠道绑定站点不一致 |
| 409 | `NO_AVAILABLE_PAYMENT_METHOD` | 支付组内所有渠道被风控拦截，或支付组不存在 / 未启用 |
| 404 | `ORDER_NOT_FOUND` | 查询时订单不存在 |

### 6.3 其他路由

| 方法 & 路径 | 鉴权 | 说明 |
|---|---|---|
| `POST /api/webhooks/payment-gateway/status` | `X-PGA-Signature` | 接收网关 `payment_status` 回调。"重试也没用"的情况（未知 event / 找不到订单 / 未知 status）记警告日志后仍返回 2xx，避免插件空转重试；真实异常返回 5xx 触发退避重试 |
| `GET /payment/{token}` | 无（token 不可猜测 + 状态须 `pending` + 未过期） | 托管收银页 |
| `POST /payment/{token}/confirm` | 同上 | 确认支付（**当前为骨架实现**，见 [12. 已知限制](#12-已知限制与注意事项)） |
| `GET /payment/expired` | 无 | 链接失效页 |
| `GET /admin` | 后台登录 | Filament 管理面板 |
| `GET /` | 无 | 首页 |
| `GET /sync/products/{paymentMethod}` | 无 | 手动触发站点商品同步（同步执行，商品多时耗时很长） |
| `GET /up` | 无 | 健康检查 |

---

## 7. 权限体系

权限常量统一定义在 `App\Support\Permissions`，`PermissionSeeder` 与各 Resource 的 `canX()` 都从这里取值。权限是**全平台统一定义**的，角色才是商户自建的（`roles.merchant_id`）。

**平台级（超管专属，不出现在商户角色的可选范围）**：`merchants.manage`、`system_configs.manage`

**商户级（20 项）**：

| 分组 | 权限 |
|---|---|
| 配置 | `applications.manage`、`payment_methods.manage`、`payment_groups.manage`、`telegram.manage`、`users.manage` |
| 订单 | `orders.view`、`orders.create_manual`、`orders.ship`、`order_events.view`、`logistics_imports.manage` |
| 资金 | `finance.view`、`withdrawals.request`、`withdrawals.review`、`balance.adjust`、`orders.refund`、`orders.chargeback` |
| 争议 | `order_disputes.view`、`order_disputes.open`、`order_disputes.reply`、`order_disputes.close` |

**后台导航分组**：平台管理 / 商户配置 / 支付配置 / 订单管理 / 资金管理。列表页约定：筛选器常驻表格上方；商户名称等跨租户敏感列与筛选器**仅平台侧账号可见**（`User::isPlatformStaff()`，即超管或商户级管理员——商户用户的数据本身已被 Scope 隔离，对其无意义）。

**平台级角色**：`商户级管理员`（`roles.merchant_id = NULL`，全平台共享同一条记录，见 [3.2](#32-平台侧账号超级管理员与商户级管理员)），权限集固定为全部 20 项商户级权限 + `merchants.manage`（共 21 项）。这是目前唯一用到"平台级角色"位置的角色；超级管理员不走角色/权限判断，而是 `Gate::before()` 整体短路。

---

## 8. 系统配置项

后台「系统配置」维护，代码中通过 `SystemConfig::get() / getArray() / getBool()` 读取（带 1 小时缓存）。

| 分组 | Key | 默认值 | 说明 |
|---|---|---|---|
| exchange | `exchange.supported_currencies` | `["EUR","JPY","GBP"]` | 支持的下单币种，同时决定汇率拉取范围 |
| exchange | `exchange.surcharge_type` | `percent` | 汇损类型：`percent` / `fixed` |
| exchange | `exchange.surcharge_percent` | `0` | 汇损百分比 |
| exchange | `exchange.surcharge_fixed` | `0.005` | 固定汇损值（`surcharge_type = fixed` 时生效） |
| exchange | `exchange.history_retention_days` | `120` | 汇率历史快照保留天数（`exchange_rate_histories`），`0` 或负数表示不清理；需大于趋势页最长的 90 天窗口 |
| order | `order.platforms` | `["wordpress","shopyy","shopline","invoice","opencart"]` | 下单允许的平台类型枚举 |
| order | `order_match.min_price_ratio` | `0.4` | 末件改价的最低价格比例，低于则回溯退行 |
| order | `order_match.max_item_quantity` | `3` | 单品件数上限的临界值（0 = 不限），实际每次在 `1~该值` 随机 |
| order_event | `order_event.sync_enabled` | `true` | 是否开启订单日志同步 |
| order_event | `order_event.sync_interval` | `10` | 同步间隔（分钟），命令每分钟调度但内部按此间隔跳过 |
| order_event | `order_event.active_window_days` | `3` | 活跃窗口（天），窗口内订单每轮重拉日志 |
| payment | `payment.product_match_modes` | `["MATCH","CREATE","VIRTUAL"]` | 商品匹配模式枚举 |
| payment | `payment_link.expire_days` | `7` | 付款链接有效期（天） |
| security | `mfa.force_for_admins` | `false` | 是否强制管理员开启 MFA |
| notify | `notify.max_attempts` | `5` | 交易结果通知最大尝试次数（含首次） |
| notify | `notify.retry_intervals_seconds` | `[30,300,1800,3600]` | 第 2~5 次尝试前的等待秒数 |
| notify | `notify.response_body_max_length` | `5000` | 记录商户响应的最大字符数 |

---

## 9. 异步任务与定时调度

### 9.1 队列任务

| Job | tries | 说明 |
|---|---|---|
| `SendOrderNotificationJob` | 1 | 发送交易结果通知。**故意不用队列重试**——重试次数与间隔完全由 `OrderNotificationAttempt` 的 `attempt_number` / `next_retry_at` 管理，两套机制叠加会让实际重试次数变成乘积 |
| `SendPaymentLinkJob` | 3 | 发送付款链接邮件 |
| `SyncOrderTrackingJob` | 1 | 回传物流给插件 `/sync-tracking`，失败交人工在后台判断后手动重试 |
| `ProcessLogisticsImportJob` | 3 | 处理物流批量导入任务 |
| `SyncSiteProductsJob` | 3 | 站点商品同步（**最坏情况约 18 分钟**，Worker `--timeout` 必须覆盖） |

> ⚠️ 修改任何 Job / 队列相关代码后**必须重启 Worker**（`php artisan queue:restart`），否则常驻进程仍跑旧代码。

### 9.2 定时任务（`routes/console.php`）

| 命令 | 频率 | 说明 |
|---|---|---|
| `exchange:fetch` | 每小时 | 拉取汇率更新 `exchange_rates`，并向 `exchange_rate_histories` 追加快照 + 按保留期清理旧数据 |
| `db:backup:upload` | 每 6 小时 | 导出数据库 → gzip → 上传阿里云 OSS → 清理本地临时文件 |
| `order-events:sync` | 每分钟（`withoutOverlapping`） | 逐笔拉取插件 `/order-logs`，实际间隔由 `order_event.sync_interval` 动态控制 |
| `order-notifications:process-due` | 每分钟（`withoutOverlapping`） | 扫描到期的失败通知记录并发起下一次尝试 |
| `order-disputes:close-due` | 每 5 分钟（`withoutOverlapping`） | 自动结束已到期的争议事件（释放冻结资金） |
| `order-disputes:send-reminders` | 每 5 分钟（`withoutOverlapping`） | 24 小时内到期的争议事件发 Telegram 提醒（`reminded_at` 置位后不重发） |

### 9.3 运维命令

| 命令 | 说明 |
|---|---|
| `make:super-admin` | 创建平台超级管理员（支持 `--name` / `--email` / `--password` 非交互） |
| `make:merchant-manager` | 创建商户级管理员（同上支持三个非交互参数），自动赋平台级"商户级管理员"角色 |
| `merchants:provision-roles {merchant_id?}` | 给存量商户补建 / 刷新默认角色，幂等 |
| `permissions:rollout-order-disputes` | 一次性命令：用 `givePermissionTo()` **增量**给存量商户的默认角色补发争议相关的 4 个权限（不覆盖商户自定义权限）。仅精确匹配默认角色名，商户改过名字或自建角色的不在覆盖范围 |

---

## 10. 目录结构

```
app/
  Console/Commands/       10 个命令（汇率、备份、日志同步、通知重试、争议关闭/提醒、超管、商户级管理员、角色补建、权限补发）
  Events/                 OrderStatusChanged / LogisticsImportCompleted / OrderEventsSyncCompleted
  Exceptions/             7 个业务异常（金额不符、余额操作、回跳域名不符、渠道不可用等）
  Filament/
    Pages/                AdminDashboard（仪表盘）、TelegramSettings（单例设置页）、ExchangeRateTrends（汇率趋势，仅超管）
    Resources/            13 个资源 + Pages / RelationManagers
    Support/              FinanceSecurity（资金操作 step-up 2FA）
    Widgets/              6 个（概览、成功率、订单趋势、渠道占比、商户排行、汇率趋势）
  Http/
    Controllers/          HomeController、PaymentPageController、PaymentGatewayWebhookController、Api/OrderController
    Middleware/           ApiAuthentication（App-ID + 签名验签）
    Requests/Api/         CreateOrderRequest、SyncOrderShippingRequest
  Jobs/                   5 个队列任务
  Listeners/              SendTelegramNotification
  Mail/                   PaymentLinkMail
  Models/                 25 个模型 + Concerns/BelongsToMerchant + Scopes/MerchantScope
  Observers/              MerchantObserver（自动建角色）、OrderShippingObserver（发货状态自动机）
  Providers/              AppServiceProvider（Gate::before）、EventServiceProvider、Filament/AdminPanelProvider
  Services/               核心业务：下单、路由风控、商品匹配、支付状态、资金账务、争议、通知、物流、
                          商品同步、仪表盘统计、Telegram、商户角色开通、平台级角色开通
                          （PlatformRoleProvisioningService）+ PaymentGateway/（PGA 客户端封装）
  Support/                Permissions、RichTextSanitizer、SignatureCanonicalizer
config/                   payment_gateway.php（PGA 客户端）、filesystems.php（oss 磁盘）、services.php（汇率/翻译）
database/
  migrations/             56 个迁移
  seeders/                DatabaseSeeder → SystemConfigSeeder + PermissionSeeder + CarrierSeeder
doc/                      对接文档（见文档索引）
lang/{en,zh_CN}/admin.php 后台文案（约 830 行 / 语言）
postman/                  Postman 集合 + 本地环境（含签名预请求脚本）
resources/views/          付款页、邮件模板、Filament 自定义视图
routes/                   api.php（商户 API + webhook）、web.php（付款页 + 同步触发）、console.php（调度）
```

---

## 11. 环境要求与部署

### 11.1 前置条件

| 组件 | 版本要求 | 备注 |
|---|---|---|
| PHP | **8.2+** | 必须启用 `bcmath`、`openssl`、`curl`、`mbstring`、`pdo_mysql`、`zip` |
| MySQL | **8.0**（5.7+ 可用） | 迁移使用 `virtualAs('IF(...)')` 虚拟生成列，**SQLite 无法执行** |
| Redis | 6+ | 队列 / 缓存 / 会话 / 分布式锁 |
| Composer | 2.x | |
| Node.js | 18+ | 前端资源构建 |

> ⚠️ **本机开发注意**：macOS 上系统默认 `php` 可能是 7.4，直接跑 artisan 会报 `platform_check` 致命错误。必须用 8.2 的完整路径，例如：
> ```bash
> /opt/homebrew/opt/php@8.2/bin/php artisan migrate
> ```

### 11.2 安装步骤

```bash
# 1. 安装依赖
composer install
npm install && npm run build        # 开发环境可用 npm run dev

# 2. 配置环境变量
cp .env.example .env
php artisan key:generate            # 必须执行：Application.api_key 用了 encrypted cast，依赖 APP_KEY

# 3. 建库 + 迁移 + 种子数据
php artisan migrate --force
php artisan db:seed                 # SystemConfig + Permission + Carrier（顺序不可调，权限必须先于角色）

# 4. 创建超级管理员（不要用 make:filament-user）
php artisan make:super-admin

# 5. 存量数据库补建商户默认角色（幂等，新库可跳过）
php artisan merchants:provision-roles

# 6. 本地开发一键启动（serve + queue:listen + pail + vite）
composer dev
```

### 11.3 环境变量

`.env.example` 只含 Laravel 默认项，**以下变量需要自行补齐**：

```ini
# 基础
APP_URL=https://pay.example.com
APP_LOCALE=zh_CN                    # 后台默认语言，可选 en
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=order_system
DB_USERNAME=…
DB_PASSWORD=…

# Redis / 队列 / 缓存 / 会话
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# 邮件（付款链接）
MAIL_MAILER=smtp
MAIL_HOST=…  MAIL_PORT=…  MAIL_USERNAME=…  MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=…  MAIL_FROM_NAME=…

# 阿里云 OSS（数据库备份、争议附件）
OSS_ACCESS_KEY_ID=…
OSS_ACCESS_KEY_SECRET=…
OSS_BUCKET=…
OSS_ENDPOINT=…                      # ⚠️ 内网 endpoint 在本地开发环境不可达，本地请用公网 endpoint
OSS_PREFIX=…
OSS_SSL=true
OSS_THROW=true

# WordPress 支付网关聚合插件（PGA）
PGA_BASE_URL=…                      # 全局兜底，实际按支付方式记录的站点覆盖
PGA_TIMEOUT=15
PGA_RETRY_TIMES=2
PGA_RETRY_SLEEP_MS=300
PGA_WEBHOOK_SECRET=…                # 校验回调 X-PGA-Signature，必须与插件侧一致
PGA_WOO_CONSUMER_KEY=…  PGA_WOO_CONSUMER_SECRET=…  # 全局兜底 WooCommerce REST API 密钥（实际按支付方式记录的 domain_client_id / domain_client_sk 覆盖）

# 汇率 API（exchange:fetch）
EXCHANGE_RATE_URL=…
EXCHANGE_RATE_KEY=…

# 百度智能云机器翻译（商品名翻译）
BAIDU_TRANSLATE_API_KEY=…
BAIDU_TRANSLATE_SECRET_KEY=…
```

### 11.4 生产部署要点

**Crontab**（Laravel 调度入口）：

```cron
* * * * * cd /path/to/order-system && /usr/bin/php8.2 artisan schedule:run >> /dev/null 2>&1
```

**Supervisor 队列 Worker**：

```ini
[program:order-system-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php8.2 /path/to/order-system/artisan queue:work redis --sleep=3 --tries=1 --timeout=1800
autostart=true
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/order-system/worker.log
stopwaitsecs=1830
environment=APP_ENV="production"
```

- `--timeout` **必须 ≥ 1800 秒**：WooCommerce 商品同步最坏情况约 18 分钟，默认 60 秒会直接杀死任务；
- `stopwaitsecs` **必须 ≥ `--timeout`**：否则优雅停机时正在执行的任务会被 SIGKILL 强杀，导致数据不一致；
- `numprocs`：每个 Worker 独占一个 MySQL 连接，按服务器规格与连接数上限规划；
- `environment` 必须显式设置 `APP_ENV="production"`；
- PHP 用**绝对路径**（`which php8.2` 确认），多版本环境下默认 `php` 可能是旧版本；
- 零停机发布：`php artisan queue:restart`（写时间戳到 Redis cache，Worker 处理完当前 Job 自行退出后由 Supervisor 拉起；**依赖 Redis 连通性**）；
- 配置 `/etc/logrotate.d/order-system` 做日志轮转，轮转后确保新文件属主为 `www-data`。

**部署后检查清单**：`php artisan config:cache && route:cache && view:cache`、`php artisan filament:upgrade`、`storage:link`（如使用 public 磁盘）、确认 `APP_KEY` 与生产环境一致（换了 key 会导致已加密的 `api_key` 无法解密）。

---

## 12. 已知限制与注意事项

1. **`PaymentPageController::confirm()` 是骨架实现**（内含 `TODO`）。真实的支付跳转由下单时插件 `/pay` 返回的 `pay_url` 承担；`pending → paid` 的流转完全由网关 webhook 驱动，这个方法不做（也不应该做）状态变更。若要启用托管收银页的"选择支付方式后确认"流程，需要在此补上跳转逻辑。

2. **网关凭证明文存储**：`payment_methods` 的 WooCommerce REST 密钥 `domain_client_id` / `domain_client_sk`（现统一用于所有插件接口认证）是普通 varchar 列，无加密 cast，后台表单也未做密码掩码；已弃用的 `order_account` / `order_password` / `config_account` / `config_password` 列仍保留在库中但不再使用（可从表单与 fillable 移除，后续可加迁移清列）。相比之下 `applications.api_key` 已用 `encrypted` cast。多商户 / 对外提供 SaaS 服务前建议统一加密并按权限脱敏展示。

3. **没有任何 API 限流**：全仓库无 `RateLimiter` / `throttle` 配置。对外 API、付款页、后台登录目前都没有频率限制，单个商户或恶意请求可能影响全站。

4. **`MerchantScope` 只在登录态生效**（设计如此，见 3.1）。API / 队列 / 命令上下文里漏写 `merchant_id` 条件不会报错，只会静默返回全平台数据 —— 改动这类代码时务必自查。

5. **测试覆盖几乎为零**：`tests/` 下只有 Laravel 脚手架自带的 `ExampleTest` 占位，核心业务（金额校验、路由风控、商品匹配、资金账务、状态守卫）都没有自动化测试。改动这些逻辑时建议先补测试。

6. **汇率 API 需按实际服务商适配**：`FetchExchangeRates::fetchRatesFromProvider()` 目前按 `Http::withToken($key)->get($url, ['symbols' => …, 'base' => 'USD', 'access_key' => $key])` 的形式请求；`EXCHANGE_RATE_URL` 未配置时命令直接跳过。换服务商时只需改这一个私有方法，编排逻辑（按配置币种请求、批量更新、清缓存）不用动。

7. **多语言的三处盲区**（详见 `doc/i18n.md`）：Filament 自身界面文案不受 `lang/*/admin.php` 控制；`system_configs.description` 是数据库数据不是代码字符串；付款邮件 / Telegram 通知跟随系统全局 `App::getLocale()`，**不按商户各自的语言偏好**（`merchants` 表没有 `locale` 列）。

8. **迁移编号不连续**：`2026_07_05_000016` 被跳过（早期规划后作废，未压号）。Laravel 按文件名排序执行，不影响迁移。

9. **权限粒度到 Resource 级**：目前只控制"能不能进这个功能模块"。平台侧账号的行级隔离（超级管理员 / 商户级管理员按 `manageableMerchantIds()` 限定到自己名下的商户）以及商户内部按角色控制"能不能进模块"都已支持，但**同一商户内部**没有再细分的行级权限（如"订单管理员只能看自己创建的订单"）。需要时在各 Resource 的 `getEloquentQuery()` 里加过滤。

10. **`GET /sync/products/{paymentMethod}` 无鉴权**：这是一个手动触发同步的调试入口，且带路由模型绑定，**生产环境应当移除或加权限中间件**。

---

## 13. 文档索引

| 文档 | 内容 |
|---|---|
| `doc/api-order-implete.md` | 订单系统 API 完整对接文档（鉴权签名、create / ship / query、webhook） |
| `doc/order-create-api.md` | 创建订单接口详解（字段说明、指定渠道行为差异、错误码、幂等性、常见对接问题） |
| `doc/wordpress-integration.md` | WordPress / WooCommerce 插件对接指南（含签名参考实现、状态映射、插件设计建议、踩坑清单） |
| `doc/s-system-payment-status-notify.md` | 支付状态回调说明（payload、状态清单、主动查询接口、订单日志接口） |
| `doc/s-system-sync-tracking.md` | 物流同步接口说明（参数、承运商字段、各渠道支持情况、幂等性、升级检查清单） |
| `doc/i18n.md` | 多语言支持现状与新增语言步骤 |
| `postman/README.md` | Postman 集合使用说明（签名预请求脚本、`merchant_order_no` 写 `AUTO` 的技巧） |

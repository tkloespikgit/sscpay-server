# 重要修改与开发交接记录

仅记录影响后续开发判断的重要变化，最新记录放在前面。历史提交的简短标题不足以证明具体业务决策；本文件不反推未核实的历史。

## 2026-10-13：收款链接 Google 检索限定国家，地址按 PayPal 国家规则整理（当前工作区，未提交）

- `resources/views/checkout/show.blade.php` 的 Google Places 检索跟随国家下拉框选择，未选择时限制到首个可选国家；避免旧版组件最多同时限制 5 个国家导致多国家配置失效。结果回填前检查国家白名单及当前检索国家。
- 按用户补充的 PayPal REST 要求修正最初“所有国家都取简称”的方案：新增 `CheckoutAddress` 与共享 JSON 省州表，按官方表处理 11 个国家，澳大利亚按澳邮规则补充。例如 Tokyo → `TOKYO-TO`、Maharashtra 保留全名、Quintana Roo → `Q ROO`；意大利取二级省而非一级 region，英国取 county。未列出规则的国家保留全称/手动输入，不声称具备全部国家地区白名单。
- `CreateCheckoutOrderRequest` 对手动省州输入也做归一化和校验；有映射表的国家必填有效省州，Service 再校验以覆盖直接调用。省州值沿用现有字段落库并发给 WordPress 的 billing/shipping `state`。商户 API 协议不变，没有数据库结构变更。
- 城市取 `locality` / `postal_town` 的城镇语义，英国优先邮政城镇；移除社区兜底。缺少城镇、省州、邮编时清空旧值，切换国家清空旧地区字段。Google 请求固定英文，减少名称语言差异。
- 更新 [收款链接手册](../doc/checkout-link.md)、架构及字段口径；新增 `CheckoutAddressTest` 与 JavaScript 行为测试，扩展 `CheckoutLinkTest` 检查省州归一化、落库及网关协议字段。
- 验证：`node --test tests/JavaScript/checkout-autocomplete.test.mjs` 19 项通过；`/opt/homebrew/bin/php artisan test --filter='CheckoutAddressTest|CheckoutLinkTest'` 在 SQLite 内存库 31 项、1319 个断言通过；Pint、`npm run build`、`git diff --check` 通过。构建提示 Node 20.1.0 低于 Vite 要求。未调用真实 Google / PayPal API，远端 WordPress 插件的 PayPal 请求构造不在此仓库，未进行线上验证。
- 后续核对无 Google Key 路径：新增无 Key 的 US/CA/IT 省州名称提交归一化测试，与无效省州拒绝测试联跑 2 项、24 个断言通过，Pint 和 diff 检查通过。已知限制：CountriesNow 下拉数据仍不等于 PayPal 地区表，层级/名称不匹配可能在本地拒绝；城市没有真实性校验。手册已说明，未将其描述为所有国家地址均可通过。

## 2026-10-13：浏览器语言头不再因订单字段过短导致建单失败（当前工作区，未提交）

- 线上收款链接日志显示正常浏览器 `Accept-Language` 多语言列表超过 `orders.accept_language` 的 VARCHAR(20)，而收款请求允许 255 字符，MySQL 因字段过长拒绝插入。
- 新增迁移 `2026_10_13_000002` 将字段扩大为 nullable VARCHAR(255)，保留字段注释和历史值；`OrderCreationService` 统一在写库前限制到 255 字符，覆盖 API、收款链接等建单入口。未修改原始建表迁移。
- `CheckoutLinkTest` 新增真实语言列表完整保存和直接调用建单 Service 的超长元数据截断测试；更新数据库说明。
- 验证：使用 `/opt/homebrew/bin/php`，SQLite 内存库联跑 `CheckoutLinkTest`、`OrderCreateRetryMailTest`，26 个测试、137 个断言通过；三个 PHP 文件 Pint 及 `git diff --check` 通过。未执行生产迁移或真实支付请求，未验证 MySQL DDL；SQLite 不强制 VARCHAR 长度，不能将测试通过视为 MySQL 字段容量验证。
- 部署必须执行新增迁移，单独更新 PHP 代码无法解决旧 VARCHAR(20) 的限制。回滚收窄字段前须检查是否已有超过 20 字符的数据，避免截断历史数据。

## 2026-10-13：收款链接可配置建单后的自动跳转（当前工作区，未提交）

- 新增迁移 `2026_10_13_000001`，`checkout_links.auto_redirect` 默认 true，保留已有链接自动跳转行为；模型增加可写字段与布尔转换，后台创建/编辑增加开关及中英文说明。
- `CheckoutLinkController` 根据服务端链接配置决定是否自动进入 `pay_url`。关闭时通过 GET `/c/{slug}/payment` 展示地址及付款按钮，避免刷新重复建单；展示页保留 Host、启用校验并按会话订单、商户和收款链接限定访问。无 `pay_url` 时沿用托管付款页兜底。
- 新增 `checkout/payment.blade.php`、英文客户文案和路由，更新架构、数据库和收款链接手册。测试覆盖默认跳转、开关保存/编辑、关闭展示、提交参数不能覆盖配置、刷新、会话/Host/链接隔离。
- 联跑发现旧测试固定断言匹配后的 subtotal，和已有“匹配超额转折扣”实现不一致；改为核对原始商品明细及 subtotal-discount 净额，没有改动下单匹配逻辑。
- 验证：使用 `/opt/homebrew/bin/php`，SQLite 内存库联跑 `CheckoutLinkTest`、`CheckoutLinkFormTest`，32 个测试、153 个断言通过；补充编辑开关断言后 `CheckoutLinkFormTest` 再跑 9 个测试、44 个断言通过。变更 PHP 文件 Pint、`git diff --check` 通过；`npm run build` 成功，但提示本机 Node 20.1 低于 Vite 要求（20.19+ / 22.12+）。
- 部署需执行新增迁移；未对业务库执行迁移，未验证生产 MySQL 迁移，没有真实外部支付请求。

## 2026-10-10：修复商户通知重试重复创建（当前工作区，未提交）

- 线上错误显示调度器重复插入同订单/通知类型的第 2 次尝试。核对代码发现 `createNextAttempt()` 没有清空上一条失败记录的 `next_retry_at`，导致每次扫描重复领取，并因唯一键冲突中断本轮扫描。
- `OrderNotificationAttempt` 在事务中锁定源记录，重新校验到期及次数，创建下一次尝试并清空旧重试时间；若下一次已存在，仅清理旧调度标记，不覆盖结果或重新发送。保留金融/通知审计记录与唯一约束。
- `OrderNotificationService` 提交后投递新尝试，重复领取返回 null；`ProcessDueOrderNotifications` 仅绕过商户 scope，保留软删除过滤，按实际新投递数量报告。下一轮扫描可自动清理旧标记并继续处理其他订单，不需要手工删除历史记录。
- 新增 `OrderNotificationRetryTest`：覆盖陈旧模型重复领取、历史重复调度标记、继续处理其他订单、软删除记录排除、到期限制及完整五次失败链。与 `OrderCreateRetryMailTest`、`GatewayRefundChargebackTest` 在 SQLite 内存库联跑，13 个测试、67 个断言通过；四个 PHP 文件 Pint 及 `git diff --check` 通过。未验证真实 MySQL 并发锁、未执行生产命令或外部回调。
- 部署需重启通知队列 worker 以载入新代码。事务提交和队列投递仍是两个步骤，不提供队列投递失败的自动补偿。

## 2026-10-10：观察者状态归并、日期筛选和订单导出（当前工作区，未提交）

- 新增 `ObserverOrderStatus`，观察者端仅展示支付成功、退款/部分退款、拒付、争议中四组状态；待发货（paid）、已发货、已完成归入支付成功，争议审核中与争议中归入争议中。其他状态在 Resource 查询入口排除，详情与列表共用展示口径。
- 观察者 `OrderResource` 增加创建日期范围，包含起止当天。`ListOrders` 增加 CSV 导出，`ObserverOrderExportService` 按当前筛选/搜索导出所有匹配记录（不受分页限制），重新校验渠道及状态范围，金额保持观察者显示比例，不输出未折算金额或额外客户字段；增加中英文文案。
- 更新架构说明，新增 `ObserverOrdersTest`，覆盖状态分组、其他状态隐藏、已删除授权渠道、日期边界、跨页搜索导出、渠道隔离和金额折算。
- 验证：使用 `/opt/homebrew/bin/php`，SQLite 内存库联跑 `ObserverOrdersTest` 与 `ObserverPaymentMethodsTest`，5 个测试、107 个断言通过；变更 PHP 文件 Pint、`git diff --check` 通过。没有迁移、前端资源或真实外部请求。

## 2026-10-08：观察者可分配已删除支付方式（当前工作区，未提交）

- `ObserverResource` 的支付方式分配选项包含软删除渠道，保留 `PaymentMethod` 的租户可见范围；`Observer::paymentMethods()` 包含软删除关联，编辑账户时回显并保留已有分配。
- 新增 `ObserverPaymentMethodsTest`，覆盖软删除渠道分配、编辑保留、商户级管理员越权提交被拒绝及管理范围内渠道可分配。
- 验证：`/opt/homebrew/bin/php artisan test --filter='ObserverPaymentMethodsTest|OrderListFiltersTest|LogisticsTemplateTest'` 在 SQLite 内存库通过 22 个测试、106 个断言；本次三个 PHP 文件 Pint 及 `git diff --check` 通过。未涉及数据库迁移或外部请求。

## 2026-10-08：订单导出按当前筛选范围，筛选包含已删除渠道（当前工作区，未提交）

- `ListOrders` 取消平台账号必须选定单个商户的导出限制，沿用列表筛选和搜索，并显式限定 `manageableMerchantIds()`。超管可跨商户导出，商户级管理员只可导出名下商户，普通商户只可导出自身订单；保留物流权限检查。
- `LogisticsImportService::generateTemplate()` 支持商户 ID 为 null，保留查询全局 scope；传入单商户 ID 时追加条件，不再绕过租户 scope。克隆查询避免改写列表查询。物流上传沿用原有规则。
- `OrderResource` 支付方式筛选加入 `withTrashed()`，包含软删除渠道并保留现有租户可见范围。同步架构说明及 `OrderListFiltersTest`。
- 验证：使用 `/opt/homebrew/bin/php`，SQLite 内存库运行 `OrderListFiltersTest`、`LogisticsTemplateTest`，20 个测试、77 个断言通过；四个变更 PHP 文件 Pint 检查及 `git diff --check` 通过。未涉及迁移或真实外部请求。

## 2026-09-30：应用级自动折扣（当前工作区，未提交）

- Application 后台增加默认关闭的自动折扣开关；统一建单入口在原始金额校验后、费用计算前按原币种随机减免。当前边界采用不超过 200 减 0.01～0.10、超过 200 减 0.01～0.50；极小金额保留至少 0.01，不足减免时跳过。
- 新增迁移、Application/Order 模型字段：`original_amount` 保存请求金额，`auto_discount` 保存减免快照。幂等比较原始金额，远端失败补单及开关变化不重复抽取。与现有未提交的商品匹配折扣变更兼容。
- 更新中英文应用表单文案、架构/数据库说明及 API 金额和重试说明。查询及通知继续发送实际付款金额，客户端重试使用原始请求金额。
- 验证：系统默认 PHP 7.4 不满足依赖，改用 `/opt/homebrew/bin/php`（8.2.6）；SQLite 内存库联跑 `ApplicationAutoDiscountTest`、`OrderMatchDiscountTest`、`OrderCreateRetryMailTest`，8 个测试、134 个断言通过。核心 PHP 文件 Pint、表单及翻译语法检查、`git diff --check` 通过。
- 未对业务库执行迁移，未验证 MySQL 迁移；部署需先运行新增迁移。所有支付请求均使用测试替身。

## 2026-09-30：自动匹配尾件按原价计入，超额转订单折扣

- 修改 `OrderItemService`、`OrderCreationService`：尾件不改价、不改名；超额累加到订单 `discount`，同步商品小计及折算金额，网关折扣只抵扣一次。
- 根据未修改的原始明细恢复补单目标，避免重试累计折扣；CREATE / COPY / 同站点直连仍使用原始明细金额。
- 新增 `OrderMatchDiscountTest`，覆盖尾件、小额、恰好匹配、换汇截断、商品容量、既有折扣和重试；联跑 `OrderCreateRetryMailTest`，SQLite 内存库通过 4 个测试。无表结构变更，未连接真实支付网关。

## 2026-09-28：建立 AI 开发交接文档

### 变更与范围

- 建立根目录 `AGENTS.md`，提供阅读顺序、文档入口、关键业务边界和验证约定。
- 建立 `docs/architecture.md`、`database.md`、`decisions.md` 及本记录；内容依据 Service、模型、路由、迁移、测试源码整理。
- 更新 README 文档索引，连接新的开发文档和实际存在的 `doc/` 文件。
- 本次为文档整理，不更改业务代码和数据库。既有 `doc/` 继续作为接口/部署操作手册。

### 核对基线

- 整理开始时 Git HEAD：`dd77cee`（2026-09-28，标题 `opt`）。该提交将 API 文档改名为 `doc/api.md`，并将两份插件协议移入 `doc/wordpress/`。
- 整理开始时工作区已有 `app/Services/OrderCreationService.php` 修改：注释普通下单的应用域名一致性校验。最终核对时 HEAD 已前进到 `2186c3e`，该修改已提交。此代码及提交均不是本次文档任务执行的操作；具体行为见 [D-05](decisions.md#d-05-下单域名策略)。
- 新文档中的“已实现”来源于代码核对，不代表生产环境部署状态，也不代表本次运行过对应测试。

### 已确认的旧资料差异

| 主题 | 旧资料叙述 | 当前依据 |
|---|---|---|
| 普通下单回跳域名 | 必须等于应用网站域名 | `2186c3e` 已注释该校验；指定渠道的域名校验仍存在。`OrderCreationService` |
| 网关退款/拒付 | 只改订单状态，人工扣款 | 自动尝试记账，业务失败告警并保留补录。`OrderPaymentStatusService`、`GatewayRefundChargebackTest` |
| 风控范围和时区 | 商户/支付组时区口径 | 按渠道 ID 跨商户、系统时区、首次付款时间统计。`PaymentService` |
| 网关请求认证 | 标准 Basic Auth 或明文 Authorization | 当前实际发送 query 凭证。`PaymentGatewayService::client()` |
| 商户通知签名 | 仅顶层排序等旧描述 | 与入站 API 共用递归规范化签名。`SignatureCanonicalizer` |
| 调度清单 | 部署文档列出 8 个任务 | 当前 12 个条目，包括两项统计及两项收款域名/Cloudflare 任务。`routes/console.php` |
| 文档路径 | README 引用多份已删除/移动文档 | 本次已修正 README 索引；正文和代码注释中的旧路径仍可能存在 |

以上差异已在新交接文档中说明；旧 README/接口/部署手册的相关正文尚未逐段改写。涉及这些模块时先核对代码，尤其不要按旧文档逆转已实现的账务行为。

### 验证与后续

- 文档验证：核对新文档相对链接、引用的源码路径、Markdown 围栏与 diff 空白问题。
- 未运行 PHPUnit、前端构建、迁移或外部集成：此次只修改文档，不能将“测试文件已阅读”记为“测试通过”。
- 测试环境注意：`phpunit.xml` 默认 SQLite 内存库，迁移包含 MySQL 特有表达式；具体兼容性及生产 MySQL 迁移需要在独立任务中验证。
- 后续同步对外接口文档中的域名策略描述；本记录保留整理时的基线变化。

## 后续记录模板

```markdown
## YYYY-MM-DD：变更名称

- 变更与原因：说明用户可观察的行为以及为何修改。
- 影响范围：入口、Service、模型/迁移、任务或外部协议。
- 决策关联：D-xx（如涉及新决策，在 decisions.md 追加）。
- 数据与部署：是否需迁移、回填、配置或队列重启。
- 验证：实际执行的命令、结果；未运行的检查及原因。
- 遗留事项：明确尚未解决的差异，不把计划写成已完成。
- 提交：已存在时填写真实提交号；未提交时注明工作区修改。
```

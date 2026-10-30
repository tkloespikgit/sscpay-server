# 重要修改与开发交接记录

仅记录影响后续开发判断的重要变化，最新记录放在前面。历史提交的简短标题不足以证明具体业务决策；本文件不反推未核实的历史。

## 2026-10-30：支付组关联表展示当天负载快照

- `PaymentService::routingLoadSnapshot()`：与组内路由同口径汇总当天成交额、笔数、负载率与候选序；启用且商户可用的通道参与排序，不做金额相关限额过滤，供后台对照。
- `PaymentMethodsRelationManager` 增加当天成交额/笔数、负载率、候选序列及中英文说明；前三候选标绿，停用通道显示未参与。
- 补充 `PaymentRoutingTest` 快照排序用例与 `PaymentGroupLoadSnapshotTest` 关联表展示用例。

## 2026-10-30：支付组/支付方式表单与邮件模版布局优化

- 支付组表单：描述 Section 使用 `compact()` + `rows(2)`；Logo、统计时区叠在描述下方。时区帮助文案改为「展示偏好，风控窗口跟系统时区」。
- 支付方式编辑页：邮件模版从右栏挪到两栏下方通栏，`compact` + 编辑器 `min-height: 8rem`；上半左右两栏布局未改。

## 2026-10-28：更新 Postman 建单、查询、物流与 webhook 文档（当前工作区，未提交）

- 更新 `postman/Order-System-API.postman_collection.json` 与环境文件，整理 11 个请求，覆盖普通建单、原单幂等/网关失败补建、POST JSON 查询、物流录入、金额校验、指定渠道及两个方向的支付 webhook。修正查询误用 GET、地址缺 `/api` 和商户单号未保存的问题；在发送前保存原建单请求，超时可复用，指定渠道重试保留原渠道值。
- 签名脚本先展开变量，再使用 Web Crypto 生成商户规范化签名或网关原始请求体签名；缺凭证、无效 JSON 和未展开变量时停止发送。Webhook 与外部物流示例默认跳过，手动联调时按请求说明开启；不自动将新建的待支付订单用于物流录入。
- 按用户附件《发货状态同步 API 接口规范》v1.1（2026-10-26）加入外部 `/api/fulfillment/shipment` 的首批/同号重试与不同运单追加批次示例，明确其凭证、字段、600 秒 nonce 和去重行为与本系统 `/order/ship` 不同。当前后端未自动接入该外部接口，原 WordPress 物流链路不变；附件对部署状态的表述矛盾，未宣称五站已实测。
- 同步 `doc/api.md` 的当前域名策略、查询本地只读及双编号 AND 匹配、错误响应、首次支付通知、重试次数与时间戳说明，并链接 Postman 和现有 WordPress 协议。本轮只更新文档及 Postman 文件，未修改业务代码。
- 验证：11 个请求的 14 段脚本可编译；14 组签名核对通过（商户/站点签名对照 PHP `SignatureCanonicalizer`，网关签名对照原始字节 HMAC），26 项模拟响应断言、8 项跳过/错误检查通过，另覆盖指定渠道原单重试、超时前保存、中文/空对象/列表及 Unicode 分隔符。路由、JSON 示例、变量引用、文档相对链接及 `git diff --check` 已核对。全部为离线验证，未运行数据库测试、迁移或真实支付/邮件/物流/webhook 请求，未在 Postman 桌面客户端导入实测。

## 2026-10-29：物流驱动改商户自选 + base_url 下沉商户（当前工作区，未提交）

- 系统配置改为 `logistics_tracking.available_drivers`（JSON 枚举）；删除 `logistics_tracking.driver` 单值与 `…seventeen_track.base_url`。
- 商户设置增加 `driver` / `base_url`；门闩：平台枚举非空 ∧ 商户选用可用驱动+凭证 ∧ 订单快照。未 commit/push。

## 2026-10-28：去掉商户物流跟踪「启用」开关（当前工作区，未提交）

- 删除 `merchant_logistics_tracking_settings.is_enabled` 与设置页 Toggle；`isUsable()` 仅看凭证齐全。
- 启用门闩改为：平台驱动已选 ∧ 订单快照；出站/回调仍需商户凭证。未 commit/push。

## 2026-10-28：物流跟踪配置分层（系统选驱动 + 商户自备密钥）+ 无回填（当前工作区，未提交）

- 初版系统选驱动 + base_url；同工作区已被 2026-10-29 商户自选驱动 / base_url 下沉取代。
- 商户表与 Webhook、无回填等骨架保留。未 commit/push。

## 2026-10-28：物流轨迹 Driver 抽象 + 应用开关快照 + 异常边沿主动查（已被上条替代，未提交）

- 初版仍用系统 `.env` 密钥与 backfill；同工作区已被配置分层与无回填拍板取代。

## 2026-10-28：17TRACK Webhook 轨迹 + 物流异常筛选 + 超期未发货 TG（已被上条替代，未提交）

- 初版 Webhook 路径与 `track17_*` 命名已在同工作区被 Driver/中性字段改造取代；保留此条作演进记录。

## 2026-10-28：支付组前三候选随机分配与网关失败自动切换（当前工作区，未提交）

- 按用户要求，`PaymentService` 保留原负载/权重排序，在通过商户可用范围、启用状态和限额校验的前三个渠道中等概率随机选择，不足三个时全部参与；排除已尝试渠道后允许后续候选递补。随机结果仍可能连续相同，待支付订单不计负载，不能将此描述为严格轮转或额度预占。
- `OrderCreationService` 的普通组内路由在同次请求内遇到网关失败自动重选，默认最多尝试三个不同渠道（`payment_gateway.failover_max_attempts`，包含首选），指定渠道不切换。切换沿用本地订单与原始金额/汇率/自动折扣，重算新渠道手续费、净入账、保证金及退款/拒付手续费快照，清除旧匹配商品；候选耗尽或达到上限时保留本地单并返回最后网关错误。
- 新增 `orders.gateway_order_no` 可空唯一字段，切换时生成新的远端引用，历史及未切换订单回退本地 `order_no`。`OrderPaymentStatusService`、`PaymentGatewayService`、`OrderEventSyncService`、`SyncOrderTrackingJob` 和 webhook 模拟命令统一采用当前引用，状态落地前防止旧尝试结果更新新渠道订单；商户 API 编号与付款 token 保持不变。
- `order_events` 新增网关尝试来源并扩展软删除生成列唯一约束，防止不同渠道相同外部日志 ID 覆盖。两项迁移均保护已使用的引用或日志来源，禁止破坏性回滚。普通路由还会跳过手续费超过金额的初选候选，并将明确的商品准备异常纳入切换；程序及数据库异常不作为渠道故障吞掉。网关响应的链接、引用、WordPress ID 与 data 类型经过校验。
- 更新架构、数据库语义、D-20、商户 API 下单/幂等说明及中英文权重帮助文案，修正 `PaymentGroup` 旧优先级注释。D-20 部分替代 D-03/D-04，不修改既有域名策略。锁租期按渠道及同步建品超时预算延长，链接落库继续保留邮件补发所需的模型变化标记。保留工作区其他任务的 Postman 文件删除。
- 验证：18 个相关测试文件联跑 **209 项、1363 个断言通过**，覆盖路由、自动切换、金额/商品快照、迟到成功/失败响应、支付回调/账务、收款页、邮件、物流、日志及两项迁移保护和往返。使用显式 SQLite 内存库、空 `DB_URL` 与独立无缓存配置路径，HTTP 模拟；Pint dirty 和新增文件格式化、文档相对链接及 `git diff --check` 通过。保留中文语言包既有对齐排版，未运行无关前端构建。
- 部署须先执行新增两项迁移；本轮未在业务库迁移或调用真实外部支付，未实跑 MySQL DDL/并发锁。新远端引用只隔离本地状态处理，未提供远端取消能力；网关超时仍可能已经在旧站点创建支付单，不能将切换描述为远端事务回滚。

## 2026-10-22：后台导出保留纯数字编号和前导零（当前工作区，未提交）

- 排查后台表格导出入口，物流模板和观察者订单列表原为 CSV，Excel 自动类型推断会改变数字编号的显示或精度。两处统一改为 XLSX，共用 `XlsxExport` 按行写出，全部非金额字段显式使用文本单元格和 `@` 格式，金额保留数值和两位小数；公式样式文本按原值保存。
- 修改 `LogisticsImportService`、普通订单 `ListOrders`、`ObserverOrderExportService`、中英文上传提示及相关测试；保留商户/观察者权限、筛选与搜索条件、观察者金额比例和旧 CSV/XLS 上传兼容。更新架构和 D-19，不修改表结构、订单值、依赖或资金逻辑。
- 物流模板限制在 Excel 单表容量内（最多 1,048,574 条订单加两行表头/说明），超限时提示缩小筛选范围且不提供下载，避免自动分表后上传仅读首表造成遗漏。
- 验证：`LogisticsTemplateTest`、`OrderListFiltersTest`、`ObserverOrdersTest`、`LogisticsSpreadsheetImportTest` 联跑 46 项、458 个断言通过。覆盖实际 XLSX 类型/格式、长数字/前导零/纯数字名称、公式样式文本、空物流号填写格、金额与观察者比例、导出填写后回传、旧 CSV/XLS/XLSX 导入、筛选及租户授权；容量边界使用小规模上限验证，并验证超限通知与无下载。使用 SQLite 内存库及伪造存储/队列，未访问业务库或真实外部服务。Pint dirty、语法、文档相对链接及 `git diff --check` 通过；保留中文语言包原有排版。未进行桌面 Excel 手工操作或超百万行文件实测。

## 2026-10-22：物流上传记录页（当前工作区，未提交）

- 用户确认：上传记录入口只放订单列表页头（不做导航菜单）；超管可只读查看全部商户；"解决记录"指逐行解析记录；本期只做失败行导出 CSV，重新解析/重试留二期；记录解析起止时间但页面不展示耗时；明细保留 90 天。
- `logistics_import_tasks` 新增 `original_file_name`（历史数据为 NULL，展示回退 `file_name`）、`started_at`、`finished_at` 及 `(merchant_id, created_at)` 索引。原来 `file_name` 存的是 Filament 随机保存名，与迁移注释"原始文件名"不符，现在把语义拆开：`file_name` = 保存名，`original_file_name` = 商户本地名。上传动作改用 `storeFileNamesIn()`；OSS 路径不再带 `logistics-imports-tmp/` 前缀（历史路径不回填）。Telegram 导入完成通知改用原始文件名。
- 新增只读资源 `LogisticsImportTaskResource`（权限沿用 `logistics_imports.manage`，超管经 `Gate::before` 可查看全部商户），列表展示上传时间、上传/保存文件名、解析状态、总/成功/失败条数与操作人；详情页含摘要、文件级错误、中断提示和逐行明细 RelationManager（分页 50，可搜订单号）。状态展示把 `completed + fail_count>0` 区分为"部分成功/全部失败"，`processing` 超 30 分钟标"解析中断"。
- 新增 `logistics-imports:prune --days=90 [--dry-run]`：按 `created_at` 每批 200 条物理删除任务与明细（含已软删任务），每日 03:10 调度。只用软删除无法清理明细表，不满足保留期目标。
- 失败行导出 `LogisticsImportService::failedRowsCsv()`：只导 `status=failed`，列为 `row_number/order_no/logistics_company/tracking_number/shipped_at/tracking_url/remark/error_message`，带 UTF-8 BOM，单次上限 5000 行；导入端按表头名映射，商户改完可直接重传。
- 验证：新增 `LogisticsImportTaskLogTest` **14 项 / 44 断言**，覆盖原名回退、起止时间、文件级失败收尾、卡死判定边界、失败行导出（只含失败行）、清理命令（含软删与 dry-run）、商户隔离与超管全量可见、后台列表/详情页及明细 RelationManager 真实渲染；全量 `php artisan test` **408 项通过、4 项 MySQL 专用跳过，3828 个断言**。Pint 已格式化，`git diff --check` 未发现空白问题。
- 已在本地开发库执行新增迁移（`order_system`），未在业务库或生产执行；队列与外部接口均以模拟方式验证。遗留：`sync_status` 语义混淆、CSV 导入仅支持首次发货、同步 Job 无自动重试等既有问题不在本期范围。

## 2026-10-22：限制后台日志查看器内存占用（当前工作区，未提交）

- 排查生产日志页在 PHP 128 MiB 限制下于 Redis 调用处内存耗尽。已安装日志插件 v2.5.0 会在分页前加载所有解析记录，并把整块结果永久缓存；读取旧缓存后才比较文件指纹。插件原配置只限制单个文件，未限制累计日志量。
- 新增 `RecentLogProvider` 并通过 `AdminPanelProvider` 接入，跳过插件全量缓存的读取和写入，保留请求内复用。优先选最近修改的合资格文件，累计最多 2 MiB，以有界堆保留其中最新 1000 条；按大小上限复制到临时快照再解析，避免活跃文件增长突破预算，解析后自动删除快照。原日志文件及插件清理操作语义保留。
- 新增 `LogViewer` 页面与中英文范围说明，明确筛选、统计只覆盖当前读取范围；大文件仍会被跳过，完整历史须查看服务器日志。页面仅在平台后台显式注册、保留超级管理员校验，关闭共享页面自动发现；同步架构说明。未修改物流导入、队列通知、依赖或 PHP 内存配置。
- 验证：`LogViewerTest` 在 `php -d memory_limit=128M vendor/bin/phpunit` 下 9 项、29 个断言通过，峰值 56.50 MB；覆盖缓存绕过、空目录、累计预算、日期乱序、刷新、并发追加/快照清理、单文件限制和面板/权限边界。使用合成临时日志，无数据库迁移、业务库、真实 Redis 或外部请求。执行 Pint dirty，移除其对中文语言包的无关格式化；语法、路由和 `git diff --check` 检查通过。未部署或使用生产日志实测；上线按现有发布流程更新路由缓存。

## 2026-10-21：修复合并后的客服查询与英文文案回退（当前工作区，未暂存）

- 移除 `CustomerServiceOrderResource` 合并后恢复的四处 `searchable()` 及未使用的筛选组件引用，关闭额外全局搜索，保留五项条件提交查询、默认隐藏和商户隔离。
- 补齐 `lang/en/admin.php` 的客服权限、订单分组及订单颜色配置提示，恢复观察者 `owner` 字段标签为 `Owner`，保留独立帮助说明。`OrderStatusColorConfigTest` 新增不使用 fallback 的中英文翻译完整性检查。
- 验证：客服查询、统一颜色及配置相关四个测试文件联跑，SQLite 内存库 63 项、367 个断言通过；此前客服两项失败均消除。已执行 Pint dirty，并回退其对未改动中文语言包产生的纯空格重排；`git diff --check` 通过。测试基于当前已安装的 Filament 5.7.3，锁文件为 5.9.0，本次未同步依赖、改动暂存区、执行业务库迁移或调用真实网关。

## 2026-10-21：订单状态颜色统一由系统配置管理（当前工作区，未提交）

- 新增 `OrderStatusColor`，统一读取 `system_configs` 中的 `order.status_colors` JSON 对象。普通订单列表与详情、客服查询、观察者列表与详情复用相同映射，默认沿用普通订单列表原配色；观察者文案仍按原有分组展示，颜色按原始状态读取。公开收款结果页的状态文字复用相同映射与调色板，不改回跳结果判断或支付状态。缺失或非法颜色回退默认，未知状态为灰色。
- 系统配置的“订单”分组增加该项，超级管理员通过格式化 JSON 文本框编辑；服务端拒绝非法 JSON、非对象、未知状态键及非允许颜色，允许部分覆盖或空对象。其他 JSON 数组继续用 Repeater 编辑；保存清除配置缓存，并补齐编辑入口的超管权限检查与中英文提示。
- 新增数据迁移 `2026_10_21_111021_add_order_status_colors_to_system_configs.php`，仅插入缺失配置；Seeder 同样保留该项已有值，迁移回滚也保留管理员配置。已确认应用为 `local`，预览 SQL 后仅执行本次迁移，并通过 Boost 只读查询确认本地 MySQL 的配置为有效 JSON、包含 12 个状态；未运行其他待执行迁移。
- 验证：`OrderStatusColorTest`、`OrderStatusColorConfigTest`、`OrderStatusColorsTest` 与客服查询、观察者订单、普通列表筛选及角色配置回归联跑，SQLite 内存库 89 项、611 个断言通过，覆盖默认回退、保存后缓存刷新、非法配置拒绝、权限、原数组配置兼容、重复初始化保留自定义，以及列表、详情和公开收款结果页统一取色。Pint dirty、`git diff --check` 与 `npm run build` 通过；构建提示现有 Node 20.1.0 低于 Vite 建议版本，构建实际成功，未更改运行时或依赖。未调用真实支付接口，未部署线上。

## 2026-10-20：采购订单权益词在首次打开时固定保存（当前工作区，未提交）

- 新增迁移 `2026_10_20_134740_add_perks_words_to_purchase_orders_table.php`，为 `purchase_orders` 增加可空 JSON `perks_words`，模型按数组转换。新单生成和历史订单升级后均未初始化；首次打开采购页或详情页时，从原有 10 个候选词中随机选取 4–8 个保存，保留用户当前数量规则，后续页面复用同一数组和顺序，已保存的空数组也不重选。
- 随机与持久化逻辑移入 `PurchaseOrderService::initializePerksWords()`，两页共享 Controller 通过注入 Service 调用。Service 再次检查启用且未删除的超级管理员，首次写入在事务中锁行并重查；旧实例不会覆盖已保存结果或将其脏金额字段写入数据库。仅保存权益词，不修改 `updated_at`、金额或商品明细。当前页面从完全只读改为首次读取时初始化权益词，后续仍不写入。
- 验证：`PurchaseOrderPerksTest`、两个页面测试、`PurchaseOrderTest` 和后台测试联跑，SQLite 内存库共 70 项、564 个断言通过，覆盖跨页首次打开、重复读取、空数组、旧实例、权限、时间戳不变和写后异常回滚。Pint dirty/定向格式化及 `git diff --check` 通过。已确认应用为 `local`，预览 SQL 后仅执行本次迁移，并用 Boost 确认 MySQL 中 JSON 字段存在；未批量生成历史订单权益词，未执行其他待运行迁移。SQLite 的旧实例测试不等于真实 MySQL 并发锁压测。

## 2026-10-20：新增采购订单详情页面（当前工作区，未提交）

- 新增 `resources/views/purchase-order-details.blade.php` 及独立详情局部模板，按提供截图实现 990px 居中布局、四步时间线、订单/物流/交易方式标签、收货与卖家资料、动态商品表格、运费与整单优惠汇总和打印区域；金额与人民币符号复用本地 `AlibabaSans102-Md.ttf`。
- 新增 `purchase-orders.details` 路由，原采购页面“订单详情”链接进入新页面，并移除原先展开已删除详情区域的脚本。`PurchaseOrderController` 两页共用鉴权和数据构造，在查询记录前校验启用且未删除的超级管理员；保留用户已调整的供货联系人/采购法人映射、供应商名称和随机权益文案。
- 商品与金额继续使用进货快照，供需方资料取支付方式当前档案。收货地址取首个非空库存地址字符串，再回退公司地址；缺失物流公司和运单号显示“未设置”。不分摊或虚构逐行优惠、不新增数据库结构、不触发支付、物流或评价操作。
- 验证：`PurchaseOrderDetailsTest`、`PurchaseOrderDocumentTest`、`PurchaseOrderTest`、`PaymentMethodPurchaseOrdersTest` 联跑，SQLite 内存库共 58 项、440 个断言通过，覆盖页面数据、角色边界、未知记录、地址回退、XSS、安全图片、费用刷新和只读行为。旧页面测试按当前用户模板调整，未恢复已移除展示字段。Blade 编译、Pint dirty/定向格式化、路由与中英文键检查、`git diff --check` 通过；Safari 使用合成测试响应检查布局、鼠标/键盘标签切换及打印预览/取消后状态恢复。未在业务库执行迁移或实际打印。

## 2026-10-19：进货单接入 1688 样式采购页面（当前工作区，未提交）

- 将 `resources/1688-order.html` 的版式拆为 `resources/views/purchase-orders/show.blade.php` 与局部模板，原 HTML 留作参考。订单号、商品名称/SKU、价格、数量、金额及模拟时间直接读取保存的进货快照；保留独立内联 SVG Logo、牛头像及去重后的侧栏，商品列表按实际明细数量渲染。
- 新增 `PurchaseOrderController` 与 `purchase-orders.show` 路由，在查询记录前校验启用且未删除的超级管理员。支付方式进货单列表新增“查看采购订单”，新标签页打开；匿名及其他角色返回 403，授权用户访问未知记录返回 404。页面动态文本转义，商品图片仅使用不含凭证的 HTTP(S) 地址，缺图或加载失败显示占位。
- 供需方资料取支付方式当前档案、图片取关联站点商品当前地址，未新增资料快照或数据库结构。后台修改运费与优惠后刷新页面显示最新金额；查看页面不会建单、修改数据或调用外部支付接口。
- 验证：`PurchaseOrderDocumentTest`、`PaymentMethodPurchaseOrdersTest`、`PurchaseOrderTest` 联跑，SQLite 内存库共 45 项测试、317 个断言通过；Blade 编译、Pint dirty/新文件定向格式化、路由检查及 `git diff --check` 通过。未执行业务库迁移，未完成真实浏览器视觉验收；未改动 Vite 入口或构建资源。

## 2026-10-19：支付方式详情支持生成与维护进货单（当前工作区，未提交）

- 新增 `GeneratePurchaseOrderAction`、`PurchaseOrdersRelationManager`、`PurchaseOrderService` 和进货单主表/明细模型；支付方式详情提供生成入口及历史查看，仅启用的超级管理员可访问，执行与后续 Livewire 请求再次校验权限。生成后按支付方式发送事件刷新历史表，支持手动编辑运费和优惠并锁单重算金额。
- 商品不足 30 种全选，否则随机选 30 至总数种，每种 10–20 件；采用已保存 `price_min`（视作 USD）× 7 × 0.3 四舍五入两位小数，人民币金额全程用 bcmath。名称、SKU 和价格独立保存快照；订单号为 `33` 加 17 位随机数字，模拟交易号为下单时间 `YmdHis` 加 20 位随机数字。付款时间在下单后 1 小时内，发货在付款后 1–2 天，签收在发货后 3–5 天。
- 默认运费按商品合计 3%–8%、优惠暂按商品合计 10%–20% 随机生成；用户原需求“10–20”未写百分号，已发起澄清，当前按百分比实现并在表单明示。两项可手动填写非负金额，优惠不超过商品合计。新单仅作本地进货留档，不创建支付订单或触发网关、资金、通知队列。
- 新增迁移 `2026_10_19_174222_create_purchase_orders_table.php`，包含两张表、编号唯一约束和快照外键删除策略；增加中英文 `purchase_order.php`，更新架构和数据库说明。原有 `CheckoutLinkResource.php` 的布局修改保持原样。
- 验证：新增核心测试 19 项/97 个断言，后台测试 12 项/98 个断言；与 `ObserverPaymentMethodsTest` 联跑，SQLite 内存库共 33 项、224 个断言通过，覆盖选品/租户边界、四舍五入、时间/编号格式、快照、金额校验、事务回滚、后台显示/执行/撤权及字段错误。Pint dirty、新增文件定向 Pint 与 `git diff --check` 通过。未调用真实外部接口，未在业务库执行迁移；尚未实跑 MySQL DDL/行锁或真实浏览器交互。部署需运行新增迁移。

## 2026-10-17：待支付订单在创建满 30/90 分钟各自动补查一次（当前工作区，未提交）

- 按用户要求，`PendingOrderStatusSyncService` 保留最近 48 小时、`pending`、`paid_at` 为空及软删除过滤，改为每单最多两次，分别在创建满 30/90 分钟后查询。调度改为每分钟扫描到期订单，避免整点/半点执行造成的延后；繁忙或停机时在窗口内分轮补查，每轮每单最多一次。
- 新迁移添加 `pending_status_query_count`、`pending_status_last_queried_at` 与到期扫描索引；查询前按原次数及当前状态/时间条件原子领取，失败、未知状态和缺凭证也计次。手工查询不占用自动次数，第一次离开待支付状态后停止二查，网关客户端内部连接重试策略沿用原配置。
- 更新 `Order` 字段/cast、命令说明、调度、架构/数据库文档、D-18、README 和部署清单。首次部署须运行新增迁移；此前未记录查询次数，历史订单从 0 开始计数并保留 48 小时窗口，不能把旧查询次数臆测为已恢复。
- 验证：`SyncPendingOrderStatusesTest` 11 项、106 个断言通过；覆盖 30/90 分钟秒级边界、延迟首查仍按创建时间计时、最多两次及积压每轮一次、失败计次/耗尽、重入旧快照领取、手工查询不计、跨商户与共享软删除渠道、201 笔分批及每分钟调度。与主动查询、退款/拒付、指定渠道通知、webhook 路由、日志同步联跑，SQLite 内存库共 66 项、460 个断言通过；独立临时 SQLite 库完成迁移升级、回滚及重新升级，Pint dirty 与 `git diff --check` 通过。
- MySQL 实跑未完成：本机 MySQL 8.0.33 在独立临时数据目录初始化时发生 signal 11，未启动服务器或执行迁移；因此未验证 MySQL DDL/并发行为。全部 HTTP 与队列均模拟，未在业务库执行迁移或自动查询，未调用真实插件。

## 2026-10-17：主动查询已支付结果覆盖任意本地状态（当前工作区，未提交）

- 按用户要求，`OrderPaymentStatusService::queryAndApply()` 返回 `paid` 时绕过终态及人工审核状态守卫；本地已为 `paid` 时仍幂等跳过，查询非 `paid` 结果及 webhook 继续使用原有守卫。状态无变化时也刷新模型，避免并发更新后仍返回旧状态。
- 从 `pending/failed/cancelled/expired` 首次转为 `paid` 时复用 `BalanceService` 按净入账金额幂等记账并通知；曾收款状态恢复 `paid` 仅纠正状态，`SendTelegramNotification` 同步避免重复付款提醒。首次支付/失败时间、退款累计额、资金台账及人工审核事件/冻结均保留，不自动撤销退款/拒付或关闭审核。
- `BalanceService` 根据已有拒付本金流水阻止再次退款或拒付，避免订单改回 `paid` 后重复扣款。更新模型注释、架构、数据库语义及 D-17；下单服务、商户查询 API 和定时扫描范围沿用现状。
- 验证：新增 `OrderPaymentStatusQueryTest` 23 项、261 个断言通过，覆盖未支付/曾支付各状态恢复、重复查询、支付时间和退款金额保留、审核冻结保留、其他状态及 webhook 守卫、旧模型查询返回最新状态；与待支付同步、退款/拒付、指定渠道 Telegram、webhook 路由联跑，SQLite 内存库共 56 项、382 个断言通过。执行 Pint dirty 格式化及 `git diff --check`；HTTP/队列均模拟，未改写历史订单、连接业务库或调用真实外部接口。未验证 MySQL 并发锁行为。

## 2026-10-17：每半小时补查最近 48 小时的待支付订单（当前工作区，未提交）

- 新增 `PendingOrderStatusSyncService` 与 `orders:sync-pending-status` 命令，每 30 分钟查询全平台最近 48 小时创建、`pending` 且 `paid_at` 为空的订单。窗口包含起止时刻，排除未来创建及软删除订单；仅移除商户 scope，按 ID 分批处理以免更新状态后分页漏单。
- 每笔沿用订单锁定的渠道与 query 凭证，支持共享、已停用和软删除渠道；调用 `OrderPaymentStatusService::queryAndApply()` 统一处理状态映射、支付时间、账务幂等和通知。`OrderEventSyncService` 继续归档日志，更新职责说明及架构、README、部署清单。
- 缺凭证、远端异常及未知状态计入失败并继续后续订单；命令返回非零，调度使用防重叠锁和失败日志。新增日志仅记录订单标识、异常类型与网关错误码，不输出可能包含 query 凭证的原始异常。
- 验证：新增 `SyncPendingOrderStatusesTest` 6 项、45 个断言通过，覆盖支付入账/通知幂等、48 小时边界和跨商户筛选、失败隔离、共享软删渠道、201 单分批不遗漏及半小时调度；与日志同步、网关退款/拒付回归联跑，SQLite 内存库共 20 项、85 个断言通过。HTTP 均模拟；命令帮助及调度清单已核对，Pint dirty 与 `git diff --check` 通过。未在业务库执行状态同步，未调用真实插件或部署线上。

## 2026-10-17：修复订单事件同步在首次运行后持续跳过（当前工作区，未提交）

- `SyncOrderEvents` 原先使用 `now()->diffInMinutes($lastRun)` 判断同步间隔；已安装的 Carbon 3 默认返回有符号差值，历史执行时间产生负数，导致后续每轮都输出 `Not due yet`。
- 改为直接比较当前时间与“上次执行时间 + 配置间隔”。调度器仍每分钟评估，实际同步频率沿用 `order_event.sync_interval`（默认 10 分钟），到达边界即可执行，未来执行时间继续等待。
- 新增 `SyncOrderEventsTest`，覆盖首次运行、自定义间隔未到期、恰好到期、超过间隔及未来时间。旧实现复现 3 项失败；修复后 SQLite 内存库 5 项、17 个断言通过，同步服务使用模拟。未在业务库执行同步或调用真实插件，线上调度器与缓存锁状态仍需按部署环境核对。

## 2026-10-16：修复匹配商品长名称导致收款链接建单失败（当前工作区，未提交）

- 收款链接日志中的 MySQL 1406 来自 `order_matched_items.product_name` 的旧 `VARCHAR(255)`；站点商品名允许 500 字符，MATCH/VIRTUAL 与 CREATE 命中商品时原样复制名称，远端创建响应的名称也可能更长。
- 新增迁移 `2026_10_16_153313`，仅将匹配商品名称快照改为非空 `TEXT`，保留注释及已有数据，不截断名称，不更改原始商品表、公开输入协议或下单/资金计算。回滚前按字符数检查全表（含软删除行），存在超过 255 字符的名称时拒绝缩列，避免静默丢失历史快照；更新模型注释与数据库说明。
- `CheckoutLinkTest` 新增长名称真实收款请求回归，检查完整落库与网关 payload；新增 `OrderMatchedItemProductNameMigrationTest`，覆盖扩容/安全回滚保留快照、255 多字节字符可回滚、256 多字节字符软删除记录阻止回滚。
- 验证：与 `OrderMatchDiscountTest` 联跑，SQLite 内存库 48 项、290 个断言通过；独立临时 MySQL 8.0.33 同样 48 项、290 个断言通过，包含实际升级/回滚 DDL。全部远端支付请求使用模拟，临时 MySQL 使用独立目录、库与 Unix socket，已关闭清理；未连接或修改业务库。Pint dirty 与 `git diff --check` 通过。
- 部署须执行新增迁移；单独更新 PHP 文件不会扩容现有数据库字段。未重试或改写日志中的历史订单。

## 2026-10-16：按澄清取消订单时间筛选的日期默认值（当前工作区，未提交）

- 根据用户澄清，`OrderResource` 开始、结束时间初始均为空，不自动限定当天；仅选择日期时默认使用 `00:00:00` / `23:59:59`，继续允许手动调整时分秒及清空筛选。
- 保留 `defaultFocusedDate`，移除两处 `default`。结束控件通过局部 Alpine 初始化补充清空后重选的日末默认时刻，避免 Filament 清空时将时间归零；已有日期时不覆盖用户选择的时间。
- 更新 `OrderListFiltersTest`，SQLite 内存库 17 项、116 个断言通过；新增 `order-date-time-filters.test.mjs`，执行实际发布的 Filament 脚本及页面初始化代码，5 项通过，覆盖初始空值、选日期默认时间、清空重选及手动时间（含零点）的保留。JavaScript 测试以 VM 替代 Alpine 调度和 DOM，未进行真实浏览器异步交互验证。

## 2026-10-16：订单列表增加原币金额精确筛选与秒级时间范围（当前工作区，未提交）

- 平台和商户共用的 `OrderResource` 新增“原始币种金额”筛选，对 `orders.amount` 等值查询；保留金额字符串，不换汇或使用自动折扣前的 `original_amount`，同数值不同币种均可命中。空值取消条件，零金额正常筛选，非法数字返回空结果。
- 开始、结束时间改用支持时分秒的日期时间控件，按系统时区默认填入当天 `00:00:00` 和 `23:59:59`，因此首次打开默认筛选当天；使用完整创建时间的包含边界比较，保留用户选择的具体时间，清空可取消单边或全部时间限制。物流导出继续沿用当前列表筛选。
- 扩展 `OrderListFiltersTest`，SQLite 内存库 17 项、110 个断言通过，覆盖原币金额口径、精确匹配、零值/非法值/清空、默认当天及跨日秒级边界；Pint dirty 格式检查通过。未修改表结构或前端资源，未连接业务库或调用真实外部服务。

## 2026-10-16：订单列表显示实际入账金额（当前工作区，未提交）

- 平台和商户共用的 `OrderResource` 新增默认可见、可排序的“入账金额（USD）”列，直接读取订单 `settlement_amount`，使用工作区已有中文键 `admin.order.columns.amount_settled` 并补齐英文标签；快照缺失时显示占位文本，不回退成交额或冒充零额。
- 汇损已包含在实际汇率和 `converted_amount` 中，`settlement_amount` 再扣除两项支付手续费，与 `BalanceService` 入账口径一致；展示时不能重复减 `surcharge_fee`。详情页本来已有净入账字段，本次不更改资金计算、历史订单、观察者比例或成交额统计。
- 验证：`php artisan test --compact tests/Feature/Filament/OrderListFiltersTest.php`，SQLite 内存库 13 项、74 个断言通过；新增覆盖平台净额显示、商户按净额排序、缺失快照与零净额区分。执行项目要求的 Pint dirty 格式化与 `git diff --check`；无数据库迁移、前端资源或真实外部请求。

## 2026-10-15：创建支付链接失败时向订单商户发送 Telegram 提醒（当前工作区，未提交）

- `PaymentGatewayService::createPayment()` 在一次调用最终失败时派发 `PaymentLinkCreationFailed`，覆盖业务错误、连接重试耗尽、非预期响应、异常 data 类型和缺少 `pay_url`。不在共享 `request()` 内告警，避免 HTTP 重试重复提醒或其他网关接口误报；成功不发，通知异常隔离后原样抛出支付异常。
- `OrderCreationService` 唯一实际调用点传入可信本地 Order 上下文，首次建单与幂等补建共用；可选参数保持原单参数调用兼容。缺少上下文或系统订单号不匹配时仅记录提醒跳过日志，不按全局订单号查商户，也不把共享渠道所有者当作接收商户。进入 `createPayment()` 前的渠道检查、商品准备异常不在本次事件范围。
- 现有 `SendTelegramNotification` 队列订阅新事件，发送系统/商户订单号、商户 ID、来源、原币金额/折算金额、渠道代码/ID、站点 host、HTTP/PGA 错误码和失败原因。沿用商户启用的 Bot、限流及 default 队列，不回退系统 Bot。新增中英文模板并更新 Facade 签名、README 与架构。
- 入队事件只含标量快照，原因先屏蔽站点认证凭证和内联网关配置中的敏感值（含 URL 编码），截断至 1200 字符；其他字段限制 200 字符，发送前 HTML 转义。不发送原始请求、完整响应、异常对象或配置凭证。
- 验证：`/opt/homebrew/bin/php artisan test --filter='PaymentLinkCreationFailureTelegramTest|OrderCreateRetryMailTest|DesignatedOrderTelegramNotificationTest'`，SQLite 内存库 17 项、152 个断言通过；变更 PHP Pint、`git diff --check` 通过。新增测试覆盖实际商户 Bot 的模拟发送、最终重试仅一条事件、共享渠道商户隔离、脱敏/转义、通知异常不覆盖支付异常、无上下文/禁用 Bot、中英文消息及实际建单失败保留本地订单。全部 HTTP 为模拟，未发送真实 Telegram，未执行迁移或线上部署。

## 2026-10-14：按商户补算历史订单漏配汇损（当前工作区，未提交）

- 新增 `orders:recalculate-surcharge {merchant}`，支持 ID 或完整商户名、创建日期范围、默认预览、`--apply` 写入、`--include-existing` 和逐单 JSON 审计报告；未传日期时核对该商户全部未软删订单。业务计算集中在 `OrderSurchargeRecalculationService`，读取数据库最新汇损配置，保留各单历史原始市场汇率、原币金额及手续费金额，重算订单实际汇率、汇损、折算字段和净入账。
- 范围为已入账且无反向资金/争议历史的 paid/shipped/completed 订单，校验流水商户、幂等键、原净入账公式和原入账金额。USD、未支付、复杂资金单、快照缺失或异常单列原因跳过；默认仅补零汇损订单。重复执行不累加汇损，本次汇总只包含实际有变化的订单。
- 商户余额、冻结额、不可变台账、退款/争议、商品及远端订单不变。输出“含汇损 - 无汇损”和“新净入账 - 原净入账”两个差额，正数增加、负数扣减，由用户通过现有入口另做一次人工调整。写入先锁商户、再分批锁订单，整批事务；报告创建/写入失败不留下订单修改。报告保存原币计算基数及新旧快照，默认私有目录、文件权限 0600；终端列出需重建统计的支付日期。
- 更新 [操作手册](../doc/order-surcharge-recalculation.md)、README 命令索引、架构、数据库口径及 D-16。新单原有计算逻辑未改；本命令只修复指定历史漏配，不把快照改写作为日常行为。
- 验证：`/opt/homebrew/bin/php artisan test --filter='RecalculateOrderSurchargeTest|GatewayRefundChargebackTest|ApplicationAutoDiscountTest'`，SQLite 内存库 30 项、256 个断言通过，其中新命令 17 项、161 个断言。覆盖正负/固定汇损、历史汇率、最新配置绕过旧缓存、手续费/原币/商品/余额/台账保留、日期边界、商户/软删隔离、复杂单跳过、关联商户异常、重复执行、报告失败后两笔 UPDATE 整批回滚。变更 PHP 语法、Pint、`git diff --check` 通过；命令帮助可发现。未连接业务库运行修复、未验证 MySQL 并发锁、未执行迁移或真实外部调用。

## 2026-10-13：支付网关 webhook 接受自定义域名回调（当前工作区，未提交）

- `routes/api.php` 将订单状态 webhook 移出 `API_DOMAIN` 域名组，修复收款链接通过客户域名建单后，WordPress 向同域 `callback_url` 回调时路由不匹配的 404。URL 路径、路由名称、原始 body 验签及状态/账务编排沿用原实现；商户 create/query/ship 接口保留域名限制与 `api.auth`。
- 新增 `PaymentGatewayWebhookRoutingTest`，在明确配置 API 域名的情况下加载实际路由，验证自定义/API 域名的有效签名回调能到达状态服务、缺失/无效/原始 body 不匹配签名被拒绝、商户接口仍限制域名并鉴权。更新架构、D-15、README 与部署变量说明。
- 验证：`/opt/homebrew/bin/php artisan test --filter='PaymentGatewayWebhookRoutingTest|GatewayRefundChargebackTest'` 在 SQLite 内存库 12 项、42 个断言通过；变更路由及测试 Pint、`git diff --check` 通过。未发起真实网关请求、未执行数据库迁移或线上部署；生产如有路由缓存，部署时需重建（`php artisan route:cache`）。已失败的历史通知是否仍有插件重试需按线上情况核对。

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

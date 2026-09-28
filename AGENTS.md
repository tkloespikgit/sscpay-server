# AI 开发入口

本项目是基于 Laravel 12 / Filament 5 的多商户支付与订单管理系统。默认使用中文沟通和维护业务文档。

## 开始任务

1. 先执行 `git status --short`，阅读与任务有关的 diff；保留用户正在进行的修改。
2. 阅读 [系统架构](docs/architecture.md)，按任务选择下表中的资料，无需每次通读整个仓库。
3. 涉及数据、资金、权限或下单行为时，先读对应模型、Service、迁移与现有测试，再修改。
4. 文档描述与执行代码不一致时，核对当前工作区和 Git 历史，标记差异；不要仅为迎合旧文档而恢复业务逻辑。

## 文档导航

| 文档 | 解决的问题 |
|---|---|
| [docs/architecture.md](docs/architecture.md) | 系统入口、服务分工、核心链路、队列与调度、按任务查代码 |
| [docs/database.md](docs/database.md) | 关键表关系、金额与时间口径、唯一约束、软删除与迁移注意事项 |
| [docs/decisions.md](docs/decisions.md) | 已采用的技术决策、原因、影响和实现依据 |
| [docs/development-log.md](docs/development-log.md) | 重要变更、验证记录及交接时已知差异 |
| [README.md](README.md) | 功能概览、安装和运维说明；部分叙述滞后，已知差异见开发记录 |
| [doc/api.md](doc/api.md) | 商户 API 对接协议 |
| [doc/checkout-link.md](doc/checkout-link.md) / [Cloudflare SaaS](doc/checkout-link-cloudflare-saas.md) | 公开收款页与商户自有域名 |
| [doc/deployment.md](doc/deployment.md) / [doc/i18n.md](doc/i18n.md) | 生产部署、多语言 |
| [支付状态协议](doc/wordpress/s-system-payment-status-notify.md) / [物流协议](doc/wordpress/s-system-sync-tracking.md) | WordPress 支付插件集成 |

`docs/` 放 AI 开发交接资料，`doc/` 保留现有接口和操作手册；使用相对链接，不复制维护两套接口定义。

## 必须保持的业务边界

- **租户隔离**：`MerchantScope` 仅对登录的 `User` 生效。API、队列、CLI 必须显式限定商户；观察者使用独立 guard，按分配的支付方式过滤。不要把移除全局 scope 当成授权。
- **共享渠道**：`PaymentMethod::forMerchant()` 包含商户自有和已分配的系统级渠道，不能简化为 `where('merchant_id', ...)`。渠道可见、可使用、可编辑是不同权限。
- **下单统一入口**：API、手工下单、收款链接都复用 `OrderCreationService`。保留商户订单号幂等、金额校验、汇率/手续费快照、锁定渠道及远端补单行为。
- **域名校验现状**：普通下单对 `applications.website` 的校验已被注释（提交 `2186c3e`）；指定渠道仍校验三个 URL 与渠道域名一致。详见 [D-05](docs/decisions.md#d-05-下单域名策略)。修改前重读当前代码和 diff。
- **资金唯一入口**：总余额与冻结额写入统一经过 `BalanceService`；保留事务、商户行锁、自动入账幂等键及台账。账务金额使用十进制字符串/bcmath，不引入浮点记账。
- **状态与账务**：网关状态落地复用 `OrderPaymentStatusService`，退款/拒付当前会自动尝试记账；不要按旧 README 改回“只更新状态”。人工资金动作保留 Resource 权限校验，并复用 `FinanceSecurity` 的 TOTP 检查。
- **统计口径**：曾支付成功使用 `Order::paidEver()`；风控按系统时区、渠道 ID 跨商户统计，按 `paid_at` 归窗。成交额 `converted_amount` 与净入账 `settlement_amount` 不可混用。
- **公开收款页**：服务端构造金额、商品、回跳地址并建单，浏览器不接收应用 API 密钥；保留 Host、启用状态、Turnstile、频率和 CSRF 校验。`/c/{slug}` 与 `/payment/{token}` 各有用途。
- **协议兼容**：商户签名复用 `SignatureCanonicalizer`；网关 webhook 对原始 body 单独验签；插件客户端当前使用 query 凭证，不能直接替换为标准 Basic Auth。
- **数据演进**：生产目标为 MySQL。修改已有表时新增迁移，保留生成列唯一约束和金融审计记录；不要将正式库重建当成日常开发步骤。

## 修改与验证

业务编排放在 `app/Services/`，入口校验放在 FormRequest/Controller/Filament 表单；复用已有模型和权限助手，避免在各入口复制业务逻辑。后台文案遵循 `lang/zh_CN/`、`lang/en/` 的现有组织；收款页文案另看 `lang/en/checkout.php`。

按影响范围执行验证，常用命令如下（依赖已安装、测试环境已隔离时）：

```bash
php artisan test --filter=OrderCreateRetryMailTest
php artisan test --filter=GatewayRefundChargebackTest
php artisan test --filter=PaymentRiskPaidAtTest
php artisan test --filter=CheckoutLinkTest
vendor/bin/pint --test path/to/changed.php
npm run build
git diff --check
```

- 上述测试是选择示例，按改动范围运行；前端资源变更再跑构建。其他关联测试见架构文档的任务索引。
- `phpunit.xml` 默认 SQLite `:memory:`，但迁移有 MySQL 专用表达式/SQL，不能把 SQLite 通过视为 MySQL 迁移已验证；遇到迁移兼容错误先核对环境。
- `RefreshDatabase` 会重建测试库。若改用 MySQL，使用独立测试数据库并检查实际连接、`DB_URL` 和配置缓存，绝不能指向业务库。
- 全量测试命令为 `composer test`（包含清配置缓存）或 `php artisan test`。报告实际执行结果；未运行或被环境阻塞要写清原因。
- 文档修改核对相对链接、类/方法/路由存在性和 `git diff --check` 即可，不必触发支付、邮件、真实外部 API 或数据库迁移。
- `composer setup` 会生成密钥并执行迁移，不作为现有环境的随手初始化命令。已有加密数据依赖 `APP_KEY`，不要随意重新生成。

## 文档维护约定

- 架构/职责变化更新 `architecture.md`；表结构/数据口径变化更新 `database.md`。
- 新决策在 `decisions.md` 追加稳定编号，写清状态、原因、影响及代码依据；被替代决策注明替代关系。
- 重要变更在 `development-log.md` 顶部追加日期、变更与原因、影响文件、验证结果和遗留事项；小排版修改无需逐条记账。
- 未提交行为和已提交事实分开描述，不将观察到的实现包装成已确认的长期业务决策。
- 本文件保持为简短入口；详细业务说明放到 `docs/`，避免后续 AI 每次加载大量重复资料。

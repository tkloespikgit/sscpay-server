# 生产环境部署与运维配置

> 本文档面向**项目上线之后**的服务器运维配置，覆盖运行身份、目录权限、Crontab 定时任务、Supervisor 队列 Worker、日志轮转、Nginx / PHP-FPM 与发布流程。
>
> **核心约定：所有常驻进程与定时任务统一以 `www-data` 用户运行**（Nginx worker、PHP-FPM、Cron、Supervisor 队列 Worker），避免不同身份写文件导致 `storage/` 权限错乱。

## 目录

- [0. 占位符与运行身份](#0-占位符与运行身份)
- [1. 前置软件](#1-前置软件)
- [2. 目录与权限（www-data 属主）](#2-目录与权限www-data-属主)
- [3. 生产环境变量（.env）](#3-生产环境变量env)
- [3.1 Telegram 快速绑定](#31-telegram-快速绑定)
- [4. Crontab 定时任务](#4-crontab-定时任务)
- [5. Supervisor 队列 Worker](#5-supervisor-队列-worker)
- [6. 日志轮转（logrotate）](#6-日志轮转logrotate)
- [7. Nginx + PHP-FPM（www-data 运行）](#7-nginx--php-fpmwww-data-运行)
- [8. 首次部署流程](#8-首次部署流程)
- [9. 日常自动部署](#9-日常自动部署)
- [10. 上线检查清单](#10-上线检查清单)
- [11. 常见坑与排查](#11-常见坑与排查)

---

## 0. 占位符与运行身份

本文所有配置中的路径 / 二进制请按实际环境替换：

| 占位符 | 示例值 | 说明 |
|---|---|---|
| `APP_PATH` | `/var/www/sscpay-server` | 项目根目录 |
| `PHP_BIN` | `/usr/bin/php8.2` | **PHP CLI 绝对路径**，用 `which php8.2` 确认；多版本环境下 `php` 可能指向旧版本 |
| `RUN_USER` | `www-data` | 统一运行用户（Debian/Ubuntu 默认；CentOS/RHEL 上通常是 `nginx` 或 `apache`，请全局替换） |
| `LOG_DIR` | `/var/log/sscpay-server` | Worker 日志目录 |

**运行身份一览（全部 `www-data`）：**

| 进程 | 管理者 | 运行用户 |
|---|---|---|
| Nginx worker | systemd | `www-data` |
| PHP-FPM pool | systemd | `www-data` |
| `schedule:run`（定时任务） | cron | `www-data`（`crontab -u www-data`） |
| 队列 Worker（`queue:work`） | supervisor | `www-data`（每个 program `user=www-data`） |

---

## 1. 前置软件

| 组件 | 版本要求 | 备注 |
|---|---|---|
| PHP-FPM | **8.2+** | 必须启用 `bcmath`、`openssl`、`curl`、`mbstring`、`pdo_mysql`、`zip` |
| MySQL | **8.0**（5.7+ 可用） | 迁移使用 `virtualAs('IF(...)')` 虚拟生成列，**不兼容 SQLite** |
| Redis | 6+ | 队列 / 缓存 / 会话 / 分布式锁 |
| Supervisor | 4.x | 守护队列 Worker |
| Nginx | 1.18+ | Web 服务器（也可用 Apache，本文以 Nginx 为例） |
| Composer | 2.x | 依赖安装 |
| Node.js | 20.19+（20.x）或 22.12+ | Vite 7 要求；**仅构建期需要**（`npm run build`），可在 CI / 本地构建后上传完整 `public/build` |
| `mysqldump` | 随 MySQL 客户端 | `db:backup:upload` 备份命令依赖，确认 `which mysqldump` 在 cron 的 `PATH` 内 |

---

## 2. 目录与权限（www-data 属主）

```bash
# 1. 代码与依赖目录整体归属 www-data
sudo chown -R www-data:www-data /var/www/sscpay-server

# 2. 需要可写的目录（日志、缓存、上传、备份临时文件）
sudo find /var/www/sscpay-server/storage -type d -exec chmod 775 {} \;
sudo find /var/www/sscpay-server/storage -type f -exec chmod 664 {} \;
sudo chmod -R ug+rwx /var/www/sscpay-server/bootstrap/cache

# 3. Worker / 备份日志目录
sudo mkdir -p /var/log/sscpay-server
sudo chown -R www-data:www-data /var/log/sscpay-server
```

要点：

- `storage/app/backups/` 由备份命令按需创建，属主必须是 `www-data`（cron 以 www-data 运行才能写入）。
- `.env` 含密钥，权限收紧：`sudo chmod 640 /var/www/sscpay-server/.env && sudo chown www-data:www-data /var/www/sscpay-server/.env`。
- **禁止用 root 跑 artisan / queue:work**，否则会在 `storage/` 里生成 root 属主文件，后续 www-data 进程写入时报权限错误。

---

## 3. 生产环境变量（.env）

在 README「11.3 环境变量」基础上，**上线必须确认以下项**：

```ini
APP_ENV=production
APP_DEBUG=false                 # 生产必须关闭，避免泄露堆栈与配置
APP_URL=https://pay.example.com
APP_TIMEZONE=PRC                # 调度按此时区计算到期（config/app.php 默认 PRC）
APP_LOCALE=zh_CN

# 队列 / 缓存 / 会话统一走 Redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=…

# ⚠️ 关键：必须 > 慢队列 Worker 的 --timeout（下文设为 1800），否则长任务
# 未跑完就被 Redis 判定"丢失"并重复派发，导致商品同步被并发执行两次。
# 框架默认仅 90 秒，上线前务必显式配置。
REDIS_QUEUE_RETRY_AFTER=1900

# 失败任务落库，便于排查
QUEUE_FAILED_DRIVER=database-uuids

# 域名限制（按实际后台 / API 域名填写；留空则不限制域名）
FILAMENT_PLATFORM_DOMAIN=sma.example.com
FILAMENT_MERCHANT_DOMAIN=applo.example.com
API_DOMAIN=apios.example.com       # 仅限制商户 API，支付网关 webhook 不限制域名

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=order_system
DB_USERNAME=…
DB_PASSWORD=…

# 阿里云 OSS（数据库备份 / 争议附件）——生产可用同地域内网 Endpoint 省流量
OSS_ACCESS_KEY_ID=…
OSS_ACCESS_KEY_SECRET=…
OSS_BUCKET=…
OSS_ENDPOINT=…
OSS_PREFIX=…
OSS_SSL=true
OSS_THROW=true

# 邮件 / 支付网关插件 / 汇率 / 百度翻译，见 README 11.3
MAIL_MAILER=smtp
PGA_WEBHOOK_SECRET=…
EXCHANGE_RATE_URL=…
EXCHANGE_RATE_KEY=…
BAIDU_TRANSLATE_API_KEY=…
BAIDU_TRANSLATE_SECRET_KEY=…
```

> `APP_KEY` 必须与生产环境保持一致：`applications.api_key` 用了 `encrypted` cast，换 key 会导致历史加密数据无法解密。

### 3.1 Telegram 快速绑定

平台只需创建和维护一个通知机器人，商户无需自行创建机器人或查询 Chat ID。平台管理员通过 BotFather 取得 Token 和机器人用户名，并允许机器人加入群组；生产环境配置：

```dotenv
TELEGRAM_BOT_TOKEN=平台机器人的Token
TELEGRAM_BOT_USERNAME=机器人用户名不含@
TELEGRAM_WEBHOOK_SECRET=至少32位随机字母数字下划线或短横线
```

回调密钥独立于 Bot Token，长度为 32–256。可使用 `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` 在部署环境生成，然后写入环境配置。此机器人应专用于本系统：Telegram 每个机器人只有一个 webhook，注册会更新该机器人的既有回调。

发布新增迁移后，按实际 PHP 路径和运行用户执行：

```bash
sudo -u www-data /usr/bin/php artisan migrate --force
sudo -u www-data /usr/bin/php artisan config:cache
sudo -u www-data /usr/bin/php artisan route:cache
sudo -u www-data /usr/bin/php artisan telegram:register-webhook
sudo -u www-data /usr/bin/php artisan queue:restart
```

默认回调为 `APP_URL` 下的 `/api/webhooks/telegram`，必须是 Telegram 可访问的公网 HTTPS 地址。若 API 使用独立入口，可在注册命令传 `--url=https://实际公网域名/api/webhooks/telegram`。网关须转发 `X-Telegram-Bot-Api-Secret-Token` 请求头，不对此路径施加交互式登录或人机验证。命令先校验 Token 对应的用户名及群组能力，再注册 `message` 更新，不清空已有待处理消息。更换 Token、回调域名或 Secret 后重新注册并刷新配置缓存、重启队列；更换 Token 也会使未完成的绑定链接失效。

商户在「服务配置 → Telegram 通知」选择「快速绑定」，点击个人或群聊按钮生成链接，再前往 Telegram：个人聊天点 Start，群聊由群主/管理员选择群并添加机器人，确保机器人有发言权限。链接 10 分钟有效且只能用一次，不应转发；后台会自动显示绑定结果，可发送测试消息、关闭通知或解除绑定。每个商户当前只有一个接收目标，完成新绑定会替换旧目标并开启通知；已有自建机器人沿用原配置，手动模式也可继续使用。

实现采用 Telegram 官方的 [深链接机制](https://core.telegram.org/bots/features#deep-linking) 和 [webhook Secret Header](https://core.telegram.org/bots/api#setwebhook)。平台凭证不会发给商户浏览器，未绑定的商户不会自动使用平台机器人。


### 3.2 区块链系统配置与升级

2026-11-16 起，区块链平台参数统一在「系统配置 → 区块链收款」维护。`APP_URL` 继续保留 `.env`；商户扩展公钥、核对地址及资产网络仍保存在支付方式模板的 JSON 中。服务端不保存钱包私钥。

此次升级新增两份迁移：`2026_11_16_163325_add_secret_value_to_system_configs_table` 和 `2026_11_16_163325_move_crypto_runtime_settings_to_system_configs`。部署代码后先执行迁移，再重新生成配置缓存及重载任务：

```bash
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan queue:restart
php artisan schedule:interrupt
```

首次升级前保留原 `.env` 和旧配置缓存供一次性导入；迁移优先使用旧有效 `services.tatum` 缓存，缓存不存在的键再读环境／环境文件。已存在的数据库值不会被覆盖，包含明确关闭和清空。新安装五个开关默认关闭，凭证及确认策略默认空。迁移不调用 Tatum、不创建支付方式、不重算原订单／账本。完成后核对后台配置，可移除旧 `TATUM_*`／`CRYPTO_*` 环境变量；运行时不再使用它们。保留原 `APP_KEY`，不能重新生成，否则已加密数据无法解密。

日常改后台参数无需再次运行 `config:cache` 或重启队列，后续操作读取新值。密钥输入留空表示保留，列表只显示“已设置（加密）”；清除需显式打开“明确清除此密钥”。不同环境分别配置，尽量使用独立 API Key。正在使用的订阅凭证不可直接替换；应先关闭新下单、保持后台任务运行，等待订阅有完整扫描覆盖并安全转冷后更换。新订阅创建前自动同步新 HMAC。回调域名改变不会重写已有订阅 URL，原域名需继续可达。配置数据回滚保留值，存在密文时列迁移拒绝回滚删除。

先填齐并核对该环境的 API Key 与 HMAC，再开放新下单；本地缺少任一凭证时不会创建监控记录。凭证更换保护也包含准备中、失败待重试等尚未确认安全退订的记录，不能仅凭“当前没有活跃订阅”绕过。

BTC 测试需在独立数据库／Redis 环境填写以下后台键；这里的 2 和 3600 仅为测试示例，不是生产确认策略：

| 后台配置键 | BTC 测试填写 |
| --- | --- |
| `crypto.api_key` | 有 BTC/USD 行情权限的 Tatum Key，加密保存 |
| `crypto.testnet_key` | 测试网 RPC／派生／订阅 Key，加密保存 |
| `crypto.testnet_hmac_secret` | 独立随机 HMAC，16–100 字符，加密保存 |
| `crypto.webhook_base_url` | 公网 HTTPS origin，不带回调路径 |
| `crypto.utxo_min_confirmations.bitcoin-testnet` | `2` |
| `crypto.genesis_hashes.bitcoin-testnet` | 与实际 Tatum 测试 RPC 核对的 64 位创世块哈希 |
| `crypto.history_max_head_age_seconds` | `3600` |
| `crypto.quote_markup_percent` | `0`，首轮不加报价上浮 |
| `crypto.quote_ttl_seconds` | `3600`，报价有效一小时 |
| `crypto.max_market_price_age_seconds` | `300`，行情最多五分钟旧 |
| `crypto.subscription_ttl_seconds` | `7200`，订阅两小时，实际期限至少覆盖报价 |
| `crypto.subscription_ttl_overrides` | `{}`，使用全局订阅时长，不能填 `[]` |
| `crypto.subscription_reopen_max_renewals` | `3` |
| `crypto.history_scan_interval_seconds` | `300`，补查健康窗口；不是定时任务执行间隔 |
| `crypto.timeout` | `10`，地址派生／行情请求超时秒数 |
| `crypto.connect_timeout` | `3`，地址派生／行情连接超时秒数 |
| `crypto.subscriptions_enabled` | 开启订阅及回调 |
| `crypto.verification_enabled` | 开启核验 |
| `crypto.history_enabled` | 开启补查 |
| `crypto.settlement_enabled` | 开启入账 |
| `crypto.checkout_enabled` | 其余配置核对后开启新建单 |

其余网络的确认数在 `crypto.evm_min_confirmations.*`／`crypto.utxo_min_confirmations.*`／`crypto.tron_min_confirmations.*` 下；BTC／LTC／TRON 链身份在 `crypto.genesis_hashes.*` 下；测试代币合约在 `crypto.test_tokens.ethereum-sepolia` 和 `crypto.test_tokens.tron-shasta`。`crypto.timeout` 和 `crypto.connect_timeout` 控制地址派生／行情请求超时，其他链查询仍保留各适配器的短超时预算。

初次配置仍需初始化支付模板和权限（有需要时运行 `CryptoPaymentTemplatesSeeder`／`PermissionSeeder`），本次迁移不授权商户。测试步骤和验收结果记录沿用[开发方案第 13 节](../docs/blockchain-payment-plan.md#13-测试容量与实施顺序)。仅关闭 `crypto.checkout_enabled` 会停止新建链上订单，已有订单继续处理；其余开关是独立的运维控制，关闭会停止对应监听／核验／补查／入账能力。

#### 3.2.1 BTC 测试网络、钱包和平台凭证

以下按当前代码整理，使用独立测试数据库、缓存、商户和钱包；不是已完成真实链验收的记录。

1. `APP_URL` 填测试付款站点的完整 HTTPS origin。`crypto.webhook_base_url` 填 Tatum 能访问的公网 HTTPS origin，可与付款站点相同，只填域名，不附加 `/api/...`。系统会生成 `/api/webhooks/tatum/testnet/{监控UUID}`；该路径须能接收外部 POST，反向代理保留原始请求体和签名头，不能出现登录／人机验证页。
2. 准备有 BTC/USD 行情权限的 Key 填 `crypto.api_key`，有测试 RPC、v3 公钥地址派生、v4 订阅管理及地址历史 Data API 权限的 Key 填 `crypto.testnet_key`。即使收测试币，当前代码仍用前者获取 BTC/USD 参考报价。独立随机 HMAC 填 `crypto.testnet_hmac_secret`，16–100 字符；例如在本地用 `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` 生成 64 位十六进制字符串。系统创建订阅前会同步 HMAC。只测试 BTC 时，主网 HMAC、其他链确认参数及测试代币合约可留空。
3. 用测试 Key 调用 [Tatum 支持网络接口](https://docs.tatum.io/reference/getsupportedalertchains) `GET /v4/subscription/chains`，确认目标测试网络列有 `ADDRESS_EVENT`。官方说明该列表不按 Key／套餐过滤，仍要由后续订单验证实际创建权限；不能只凭列表判断账号已具备权限。
4. 当前代码的 BTC 测试网络键是 `bitcoin-testnet`，RPC 固定指向 `https://bitcoin-testnet.gateway.tatum.io/`。Tatum 另列独立 Testnet4 与 Signet 网关，不能直接把模板改成 `bitcoin-testnet4` 或把 Testnet4 创世哈希填到现有配置中当作切网。见 [Tatum Bitcoin 网络列表](https://tatum.io/chain/bitcoin)。在所用 RPC 分别提交以下 JSON，检查 `chain`、`initialblockdownload=false`、链头进度和创世哈希：

```json
{"jsonrpc":"2.0","id":1,"method":"getblockchaininfo","params":[]}
```

```json
{"jsonrpc":"2.0","id":2,"method":"getblockhash","params":[0]}
```

如果确认为 Bitcoin Testnet3，`crypto.genesis_hashes.bitcoin-testnet` 应与下面的 [Bitcoin Core Testnet3 标识](https://github.com/bitcoin/bitcoin/blob/v28.0/src/kernel/chainparams.cpp)逐字一致；不一致先查网络映射，不直接接受供应商返回的任意哈希：

```text
000000000933ea01ad0ee984209779baaec3ced90fa3f408719526f8d77f4943
```

5. 在同一测试网络准备发送钱包和商户收款派生钱包。测试币先领到发送钱包，留足链上手续费，之后精确转入订单地址；入口可查 [Tatum 测试币索引](https://docs.tatum.io/docs/testnet-faucets)，逐一确认具体网络。不要把水龙头直接转入金额不可控的订单地址当作正常付款测试。
6. 收款钱包在本地备份／恢复后，导出与 Tatum v3 兼容的扩展公钥和索引 0、1 的地址。Tatum 公布的通用测试分支是 `m/44'/1'/0'/0`，两个校验地址为该分支下的 `/0`、`/1`；账户层 `m/44'/1'/0'` 与接收分支多差一层，不能直接混用。脚本类型也须与实际派生结果一致。见 [Tatum 派生规则](https://docs.tatum.io/docs/mnemonic-derivation-path)、[BTC 公钥派生接口](https://docs.tatum.io/reference/btcgenerateaddress)。服务端仅接受 `xpub`／`tpub`，不接受 `ypub`／`zpub`／`vpub`、普通收款地址或私钥。助记词及私钥仅由本地钱包保管；不能提交到后台或在线转换网页。

#### 3.2.2 支付方式配置填写表

若模板列表没有 `Crypto / Bitcoin`，先在该测试环境执行 `php artisan db:seed --class=CryptoPaymentTemplatesSeeder --force --no-interaction`；它只补缺失模板，已有同名模板不会被覆盖。权限按现有角色管理流程分配，系统配置仍仅超级管理员可编辑。

新建测试支付方式，首轮直接归属测试商户；若使用管理员共享渠道，则先授权给该商户。名称可填“BTC 测试收款”，唯一代码例如 `btc_test`；支付类型选“区块链支付”，模板选 `Crypto / Bitcoin` 并启用。已有法币渠道不能直接切换支付类型。模板的网络默认主网，必须主动切换到测试网络。

| 页面字段／JSON 键 | 填写内容 |
| --- | --- |
| 配置版本 `config_version` | `1` |
| 网络 `network` | `bitcoin-testnet` |
| 资产 `asset` | `BTC` |
| 只读扩展公钥 `extended_public_key` | 上一步本地准备并核对的测试收款分支 `tpub...`／兼容 `xpub...`，不能填写示例占位文本 |
| 派生兼容方案 `derivation_profile` | `tatum-v3-bitcoin-v1`，不是填写 HD 路径的输入框 |
| 本地恢复校验地址 `recovery_address_0` | 同一分支的索引 0 地址 |
| 本地恢复校验地址 `recovery_address_1` | 同一分支的索引 1 地址，必须与索引 0 不同 |
| 确认策略引用 `confirmation_policy` | `bitcoin-configured-v1`；确认数 `2` 填在系统配置，不填在这里 |
| 代币合约 `contract_address` | BTC 不填写；标准 Bitcoin 模板不会提供此字段 |

这些字段保存到支付方式 `config` JSON；Tatum Key、HMAC、报价有效期、创世哈希不应添加到模板 JSON。校验地址是本地恢复能力的核对样本，不是所有订单重复收款的地址。新公钥源首次分配订单地址从索引 2 开始；已使用的公钥源继续既有全局计数，不因新增渠道或商户重置。

费用区域首轮填交易百分比费 `0`、固定费 `0`；第二轮改百分比费为 `1`（即 1%，不是 `0.01`）、固定费仍为 `0`，创建新订单验证净额。若该商户有独立费用覆盖，以商户生效配置为准。固定费的单位是 USD，会按冻结币价折算 BTC。链上钱包实际收到总 BTC，平台账本记录扣服务费后的净 BTC；本系统不会链上分走服务费。

建一个仅含此渠道的测试支付组，启用支付组及渠道关联，将测试应用绑定该组；首轮关闭该组的组合付款，单笔限额允许测试金额，避免误路由到法币渠道。保留正常商户、应用、渠道权限与启用校验。

#### 3.2.3 从下单到到账的测试顺序

1. 按 3.2 完成迁移并核对后台值；本节测试值只用于隔离环境。填齐凭证、网络策略及支付方式后，依次开启订阅、核验、历史补查、入账，最后开启 `crypto.checkout_enabled`。日常保存配置无需再执行 `config:cache`；旧订单确认策略、费率和报价仍取原快照，改配置后用新订单测试。
2. 保持调度和队列运行。本地测试可在两个终端分别执行 `php artisan schedule:work`、`php artisan queue:work`；服务器已有调度／Supervisor 时沿用原运行方式。链上准备、订阅、核验、入账及每三小时的补查派发由 Scheduler 调用，单独启动 Queue Worker 不会启动这些定时命令；default 队列 Worker 负责地址补查与手动查询，通知沿用原队列。
3. 从现有统一下单入口创建一笔 `USD 10.00` 订单，使用测试应用和仅含 BTC 渠道的支付组，商户订单号每笔唯一。提交的仍是法币金额／币种，不将 API 的 `currency` 改为 BTC。先用 USD 验证收款，再用 AUD／GBP 等验证法币原始汇率快照与数量上浮。订单金额须满足现有渠道限额。
4. 打开返回的付款链接，等待公钥核对、地址派生、报价、历史补查、订阅及交接补查完成。付款页应展示 `bitcoin-testnet`、BTC 应付数量、独立地址和报价到期时间。Tatum 中应有该地址的有效订阅。没有通过核对前页面不显示可付款地址；不要向两个校验地址付款。
5. 报价有效时，从发送钱包将页面要求的 BTC **精确足额**转到该订单地址，发送手续费另付，不勾选从收款金额扣除矿工费。保存订单号、地址、报价数量、txid、上链高度与时间。当前迟到判定看规范区块时间，报价到期前广播不保证到期前上链。
6. 达到两次确认并完成链上核验后，检查订单支付成功、「虚拟币资金」中 `bitcoin-testnet / BTC` 的净余额和入账凭证，以及服务费。首次零费时净额等于应付；第二轮 1% 费用时按聪向上舍入服务费后扣除。例：应付 `0.00010000 BTC`、1%、固定费 0 时，服务费为 `0.00000100 BTC`，净额 `0.00009900 BTC`。实际应付以冻结报价为准，法币余额不应增加。
7. 同时核对真实 Tatum 投递成功和本系统验签接收记录。只靠历史补查到账只能证明补查可用，不能把 webhook 标为通过。反复刷新付款页及重复运行核验／入账后，余额与原始收入凭证应只增加一次；再建第二单确认地址不同。

BTC／LTC 的规范区块时间允许最多提前服务器时间 7200 秒，历史读取和收款核验使用同一上限，EVM／TRON 仍为 300 秒。2026-11-18 的测试环境实测 BTC Testnet3 链头提前 7019 秒；旧版统一五分钟校验会报 `Invalid history timestamp`，付款页因此持续等待。依据见 [Bitcoin Core](https://github.com/bitcoin/bitcoin/blob/v28.0/src/chain.h) 和 [Litecoin Core](https://github.com/litecoin-project/litecoin/blob/v0.21.4/src/chain.h)。这项修复保留原始区块时间和报价期限判断；若测试链时间提前接近两小时，前文一小时报价示例可能使后续付款转人工审核，正常足额测试应在建新单前为测试环境设置足够的报价时长，例如 `crypto.quote_ttl_seconds=14400`，并核对订阅时长。不能通过改写区块时间或延长已有报价规避审核。

需要定位后台处理阶段时，可在该隔离测试环境逐项运行以下现有命令；不要以命令退出成功替代订单／链上结果核对，部分命令存在下一次重试时间，链上确认仍须真实出块：

```bash
php artisan crypto:prepare-checkouts --limit=5 --no-interaction
# 查询实际付款请求地址；替换以下示例商户 ID、crypto_payment_requests.id
php artisan crypto:scan-history bitcoin-testnet --merchant=123 --request=456 --limit=100 --no-interaction
php artisan crypto:check-addresses --limit=1000 --no-interaction
php artisan crypto:maintain-subscriptions --limit=5 --no-interaction
php artisan crypto:verify-receipts --limit=25 --no-interaction
php artisan crypto:settle-payments --limit=100 --no-interaction
```

`crypto:prepare-checkouts` 会输出本轮处理数量、每条记录的状态／阶段；没有到期任务时显示下次计划时间或没有待办，避免将静默退出误判为准备成功。失败时另输出异常类型、源文件／行号及可用的提供商 HTTP 状态码，不输出密钥、请求 URL 或响应正文。已有 `checking / preparation_retry` 记录同步修复后按原重试时间继续，不需删除订单、回收派生索引或重建付款请求。

若出现 `Provider HTTP status: 429`，表示提供商限流，见 [Tatum 429 说明](https://docs.tatum.io/docs/error-429)。RPC、派生、行情、历史查询和订阅读取会遵守 `Retry-After`（秒数或 GMT HTTP 日期），每次调用仍最多两次尝试；缺失／无效值至少等一秒。单次建议等待超过五秒时不占用后台处理窗口、不提前进行第二次请求，保留既有任务重试机制。相同 HMAC 的 PUT 配置可安全重试，创建订阅 POST 仍先查回结果，不盲目重复创建。持续限流时核对 Tatum 控制台的实际套餐、账户并发和剩余 Credits；退避处理不代表增加供应商配额。

`checking` 且 `0 failed` 只代表本轮没有抛异常，地址仍未发布；订阅未通过核对也会正常返回并持久化错误。若地址预留已是 `derived`、付款请求已生成，而监控记录为 `remote_identity_mismatch`，应检查订阅身份，而非重建地址。Tatum 可能返回顶层 `chain=bitcoin-testnet`、`attr.chain=BTC`；代码仅在顶层网络与当前监控完全一致时接受所属网络的旧币种代码（BTC／LTC／ETH／TRON），同时继续核对订阅 ID、地址、回调和启用状态。缺少明确网络的币种代码、主／测试网冲突仍拒绝，接口兼容依据见 [Tatum 网络字段说明](https://docs.tatum.io/reference/getsupportedalertchains)。已有报错订阅可在后续准备中原地核对恢复，保留当前地址、付款请求和订阅 ID。

首轮成功后再做异常测试：

| 用例 | 预期结果 |
| --- | --- |
| 少付／多付 | 不按正常足额款直接完成入账；核验及补查后进入对应状态或人工复核 |
| 报价过期后转入旧地址 | 找回原订单，按迟到款处理，不命中新订单 |
| 测试环境短暂阻断回调，再点订单“查询链上到账” | 不等待三小时即入队，地址 API 发现交易后由 RPC 核验；区分回调重投和补查，不重复入账 |
| 过期链接重新打开／重新报价 | 先补查旧地址；有款先核验处理，无款且覆盖完整、链头已越过到期时间后才允许显式重新报价及新地址 |
| 单独关闭新下单开关 | 新建链上单停止，已有单的发现、核验和入账继续 |

测订阅到期时，可对**新测试订单**使用报价 `300` 秒、订阅 `600` 秒等短值。订阅期限至少覆盖报价；到期在历史功能开启、远端身份核对及取消成功后，记录实际取消时间并创建第 3／7 天计划，不再等待持续扫描接管。已发现的未确认交易继续核验。BTC 长时间不出块仍可能使付款页的链头新鲜度检查失败，不能绕过。退款／取款依旧是人工审核核销，真实钱包归集另测。

#### 3.2.4 两轮地址补查升级与验收

新增迁移 `2026_11_18_111740_create_crypto_address_checks_table`，需要上线执行。已有 `deploy.sh` 自动按“迁移 → 初始化旧退订计划 → 重启队列”推进；无需在正式库重建数据。若不用该脚本，沿用本站部署账号和 PHP 版本执行：

```bash
php artisan migrate --force --no-interaction
php artisan crypto:check-addresses --initialize --no-interaction
php artisan queue:restart --no-interaction
```

初始化只读本地取消记录并补缺计划，不调用 Tatum，重复执行不重置已完成计划。存在审计记录时新迁移的 `down()` 会拒绝删除；生产优先向前修复。原有 `schedule:run` 保持每分钟触发，代码内每三小时派发一次到期补查；手动按钮直接进入 default 队列。移除运维层自行添加的全网 `crypto:scan-history` cron，该命令现必须明确 `--merchant` 和 `--request`。

- 核对实际退订时间 T0：只产生 day3/day7 两条，due_at 分别为 T0+72 小时和 T0+168 小时。到期后最近一轮三小时调度派发，队列等待另计；不会承诺恰好在截止秒完成。
- 在独立测试环境让真实时间经过两轮，或由自动化测试使用时间旅行覆盖；不要修改服务器系统时间或正式订单计划。到期前无查询，到期后记录完成、分页进度及结果；完成后再次派发不增加 Tatum 请求。
- 在退订空档转入旧地址，第 3／7 天应归属原订单并按迟到款规则审核。第 7 天后转入不会再自动轮询；点“查询链上到账”或合法重开付款链接补查，仍不改变地址归属。
- 查询中遇到限流／失败，不记完成并保留分页。每轮最多三次失败后停止自动请求；排除故障后可点手动查询。队列超时也保留失败状态。暂停服务直到两轮均到期时，只执行第 7 天完整查询，第 3 天记录由该次结果覆盖。
- 重开付款页恢复订阅或手动查询，不重置两轮。重新报价的旧地址一起查；反复点击一分钟内复用手动任务，既有已入账交易不重复记账，多个历史地址同时有款进入审核。
- 平台／商户只能查当前权限内订单；撤销商户归属或权限后，已有页面／排队任务不能继续查询。法币订单仍使用原网关查询按钮。
- 查看系统配置 `crypto.history_scan_interval_seconds` 的新说明：它仍是付款页／人工核账的结果新鲜度窗口，**不是三小时调度间隔**。第 3／7 天和三小时周期固定于本次实现，不需要新增密钥或私钥。

两轮查询不等于两次 HTTP 调用：分页、链身份／链头、ETH 内部转账和发现交易后的 RPC 核验分别计费。真实测试需记录 Tatum Credits、索引延迟、各网络 API 权限及完成耗时。

---

## 4. Crontab 定时任务

Laravel 调度只需**一条每分钟触发**的入口，具体频率由 [`routes/console.php`](../routes/console.php) 内部定义。

### 4.1 以 www-data 安装 crontab

```bash
sudo crontab -u www-data -e
```

写入（把 `APP_PATH` / `PHP_BIN` 换成实际值）：

```cron
* * * * * cd /var/www/sscpay-server && /usr/bin/php8.2 artisan schedule:run >> /dev/null 2>&1
```

- `schedule:run`：每分钟启动一次，存在子分钟任务时会持续运行到该分钟结束，每 10 秒启动资金结算/释放任务；Laravel 按 `APP_TIMEZONE`（PRC）判断到期。两项资金命令使用后台进程和防重叠锁，必须允许 PHP CLI 派生后台进程；**cron 仍只需要这一条**。多节点应共用可靠缓存并明确只由指定调度节点运行。
- ⚠️ **不要把 `schedule:finish` 单独写进 crontab**：它是框架内部命令（`hidden=true`），签名为 `schedule:finish {id} {code=0}`，`{id}` 是调度事件的互斥名（`mutexName()`）。`runInBackground()` 任务结束时框架会自动带参调用以清理互斥锁。手动裸跑缺少 `id` 会报错。发布完成后执行 `php artisan schedule:interrupt`，让仍在当前分钟内运行的旧版本调度尽快退出；下一分钟 cron 使用新代码。
- **必须用 `crontab -u www-data`**，让定时任务以 www-data 身份运行，写入 `storage/` 的文件属主才与 Web / Worker 一致。
- cron 环境 `PATH` 精简（通常 `/usr/bin:/bin`），`db:backup:upload` 内部调用的 `mysqldump` 需在此 `PATH` 中；不在时在命令里写绝对路径或补充 `PATH=`。

> 备选：若不便用 www-data 的 crontab，可放 root crontab 并显式降权：
> `* * * * * cd /var/www/sscpay-server && sudo -u www-data /usr/bin/php8.2 artisan schedule:run >> /dev/null 2>&1`

### 4.2 已配置的调度任务清单

以下为常用业务调度任务，完整清单见 [`系统架构`](../docs/architecture.md#5-队列和调度)（`schedule:run` 每分钟评估一次，实际频率见下表）：
来自 [`routes/console.php`](../routes/console.php)，共 **13 个调度条目**（每分钟启动入口，实际频率见下表）：

| 命令 | 频率 | 说明 |
|---|---|---|
| `exchange:fetch` | 每小时 | 拉取汇率更新 `exchange_rates`，向 `exchange_rate_histories` 追加快照并按保留期清理 |
| `db:backup:upload` | 每 6 小时 | `mysqldump` 导出 → gzip → 上传阿里云 OSS `backups/` → 清理本地临时文件 |
| `order-events:sync` | 每分钟（`withoutOverlapping`） | 逐笔拉取插件 `/order-logs` 归档订单日志，实际间隔由 `order_event.sync_interval` 动态控制 |
| `orders:sync-pending-status` | 每分钟扫描（`withoutOverlapping`） | 最近 48 小时创建、`pending` 且 `paid_at` 为空的订单按创建满 30/90 分钟各补查一次，每单最多两次，失败也计次；单笔失败继续，整轮有失败时返回非零 |
| `order-notifications:process-due` | 每分钟（`withoutOverlapping`） | 扫描到期的失败通知记录并发起下一次尝试 |
| `ad-conversions:process-due` | 每分钟（`withoutOverlapping`） | 扫描到期的广告转化通知重试记录并发起下一次尝试 |
| `order-disputes:close-due` | 每 5 分钟（`withoutOverlapping`） | 自动结束已到期的争议事件（释放冻结资金） |
| `order-disputes:send-reminders` | 每 5 分钟（`withoutOverlapping`） | 24 小时内到期的争议事件发 Telegram 提醒 |
| `fund-freezes:release-due` | 每 10 秒，后台、防重叠 | 分批释放到期固定/循环保证金；循环保证金争议期间暂停；默认单次最多 500 条 |
| `orders:settle-due` | 每 10 秒，后台、防重叠 | 补偿新单记账、启动发货周期、结算到期资金；每类默认单次最多 500 条 |
| `order-stats:aggregate --days=8` | 每天 02:00 | 滚动重算统计 |
| `order-stats:aggregate --days=2` | 每 3 小时 | 重算近期统计 |
| `checkout:check-cloudflare-ips` | 每周一 09:00 | 核对 Cloudflare IP 段 |
| `checkout:sync-domains` | 每 15 分钟 | 同步商户自有域名状态 |

资金命令通过无锁候选扫描、逐笔商户优先行锁和幂等流水处理，不将全批次包进一个事务。`--limit=1..10000` 可手动调节单次上限；生产需监测到期积压、最早未处理到期时间、锁等待、错误日志与 `order_accounting_tasks.status=manual_review`，按实测吞吐调整，不应只增加 Worker 数。当前配置以正常负载下到期一分钟内完成为目标，数据库故障或积压期间无法承诺固定延迟。不要为清理积压批量改写金融记录状态。

新增财务迁移只建立新规则所需结构，**不会回填历史订单或重算余额**。旧订单继续原入账规则；旧待审核提现继续使用原冻结额并在展示时归类。先在独立 MySQL 测试库验证升级，发布时执行新迁移；已有新结算/待办审计后禁止破坏性回滚，应前向修复。`release_at` 扩为 DATETIME，需按表规模评估 ALTER 的执行时间。

### 4.3 验证

```bash
# 以 www-data 身份列出调度计划与下次运行时间，确认无遗漏
sudo -u www-data /usr/bin/php8.2 artisan schedule:list
```

---

## 5. Supervisor 队列 Worker

### 5.1 队列与 Job 映射

系统按优先级拆成 **4 个队列**（Job 类构造函数里 `onQueue()` 指定），由快 / 慢两个 Worker 池处理，避免商品同步等长任务阻塞订单通知 / 支付链接：

| 队列 | Job / 监听器 | tries | backoff | 归属池 |
|---|---|---|---|---|
| `notifications` | `SendOrderNotificationJob` | 1 | — | 快 |
| `payment-links` | `SendPaymentLinkJob` | 3 | `[60,300,600]` | 快 |
| `default` | `SendTelegramNotification`（队列化监听器） | 无（跟随 CLI） | — | 快 |
| `low` | `NotifyAdConversionJob` | 1 | — | 慢 |
| `low` | `SyncOrderTrackingJob` | 无（跟随 CLI） | — | 慢 |
| `low` | `ProcessLogisticsImportJob` | 3 | `[60,300,600]` | 慢 |
| `low` | `SyncSiteProductsJob` | 3 | `[60,300,600]` | 慢 |

> **重试次数优先级**：Job 类内的 `$tries` / `$backoff` **优先于** `queue:work --tries`。因此下文 CLI 里的 `--tries=1` 只是"未定义 tries 的 Job"（如 Telegram 监听器、`SyncOrderTrackingJob`）的兜底值；`SendPaymentLinkJob` / `SyncSiteProductsJob` 等定义了 `$tries=3` 的 Job 仍会重试 3 次，不受 CLI 影响。

### 5.2 配置文件

`/etc/supervisor/conf.d/sscpay-server-worker.conf`（替换 `APP_PATH` / `PHP_BIN`）：

```ini
; ---- 快车道：订单通知 / 支付链接 / Telegram，永不被慢任务阻塞 ----
[program:sscpay-server-worker-fast]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php8.2 /var/www/sscpay-server/artisan queue:work redis --queue=notifications,payment-links,default --sleep=3 --tries=1 --timeout=120 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/var/log/sscpay-server/worker-fast.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
stopwaitsecs=150
environment=APP_ENV="production"

; ---- 慢车道：商品同步 / 物流同步 / 物流导入 / 广告转化 ----
[program:sscpay-server-worker-slow]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php8.2 /var/www/sscpay-server/artisan queue:work redis --queue=low --sleep=3 --tries=1 --timeout=1800 --max-time=7200
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/sscpay-server/worker-slow.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
stopwaitsecs=1830
environment=APP_ENV="production"
```

### 5.3 参数说明

- **`--queue` 列表即优先级顺序**：快车道按 `notifications` → `payment-links` → `default` 取任务，前一队列取空才看下一个。
- **`--timeout`**：单任务最长执行秒数。慢车道 `SyncSiteProductsJob`（WooCommerce 商品同步）**最坏约 18 分钟**，必须设 `1800`；默认 `60` 会直接杀死任务。快车道任务都很短，`120` 足够。
- **`stopwaitsecs` 必须 ≥ 对应 `--timeout`**：否则优雅停机时正在执行的任务会被 SIGKILL 强杀，导致数据不一致（慢车道 `1830 > 1800`）。
- **`REDIS_QUEUE_RETRY_AFTER` 必须 > 慢车道 `--timeout`**（设为 `1900`），否则 Redis 会在任务没跑完时就认为它"丢失"并重新派发，导致同一商品同步任务被并发执行两次。
- **`--max-time`**：Worker 运行满该秒数后自动重启，兜底加载新代码（配合发布时的 `queue:restart`）。
- **`numprocs`**：每个 Worker 独占一个 MySQL 连接。快车道 `3` + 慢车道 `1` = 4 个常驻连接，按服务器规格与 MySQL `max_connections` 规划，不要盲目调大。
- **`user=www-data`**：Supervisor 以 root 启动后降权到 www-data 运行 Worker，保证写文件属主一致。
- **`environment=APP_ENV="production"`**：必须显式设置，否则 Worker 可能跑在错误模式下。

### 5.4 启用与管理

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status                       # 确认两池 RUNNING

# 单独重启某一池
sudo supervisorctl restart sscpay-server-worker-fast:*
sudo supervisorctl restart sscpay-server-worker-slow:*

# 查看实时日志
sudo tail -f /var/log/sscpay-server/worker-slow.log
```

---

## 6. 日志轮转（logrotate）

`/etc/logrotate.d/sscpay-server`（只管 Laravel 应用日志）：

```
/var/www/sscpay-server/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su www-data www-data
}
```

- **`copytruncate`**：先复制旧日志再原地清空，文件 inode 不变，常驻进程（Monolog）无需重开句柄就能继续写入。**不要用 `create`**——那样 logrotate 把旧文件移走后，Monolog 仍写已被移走的旧 inode，新日志文件会一直是空的。
- **`su www-data www-data`**：以 www-data 身份执行轮转，保证属主一致；若 logrotate 以 root 运行时报父目录权限错误，这一行可解决。
- **Worker 日志不纳入这里**：`/var/log/sscpay-server/*.log` 已由 Supervisor 的 `stdout_logfile_maxbytes=50MB` / `stdout_logfile_backups=5`（见 5.2）自行轮转，两处同时轮转会冲突，交给 Supervisor 即可。

---

## 7. Nginx + PHP-FPM（www-data 运行）

> 仓库不自带 Nginx / PHP-FPM 配置，以下为参考模板，按实际域名与路径调整。

### 7.1 PHP-FPM pool（`/etc/php/8.2/fpm/pool.d/www.conf`）

```ini
[www]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
```

### 7.2 Nginx 站点（`/etc/nginx/sites-available/sscpay-server.conf`）

```nginx
server {
    listen 80;
    server_name pay.example.com sma.example.com applo.example.com apios.example.com;
    root /var/www/sscpay-server/public;
    index index.php;

    client_max_body_size 20M;   # 物流批量导入 CSV 上传

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_read_timeout 120;
    }

    # 禁止访问隐藏文件（.env / .git）
    location ~ /\. {
        deny all;
    }
}
```

- Nginx `worker_processes` 以 `www-data` 运行（`/etc/nginx/nginx.conf` 顶部 `user www-data;`）。
- `root` 必须指向 `public/`，而非项目根目录，避免 `.env` 等敏感文件被直接下载。
- 上线后建议配置 HTTPS（Let's Encrypt / 云厂商证书），并将 `APP_URL` 与各域名限制项改为 `https://`。

---

## 8. 首次部署流程

```bash
cd /var/www/sscpay-server

# 1. 依赖（PHP 用绝对路径，Node 仅构建期需要）
composer install --no-dev --optimize-autoloader
npm ci --include=dev && npm run build   # 或在 CI 构建后上传完整 public/build

# 2. 环境变量
cp .env.example .env                    # 按第 3 节填写生产配置
/usr/bin/php8.2 artisan key:generate    # 必须：Application.api_key 依赖 APP_KEY

# 3. 建库 + 迁移 + 种子（顺序不可调，权限必须先于角色）
/usr/bin/php8.2 artisan migrate --force
/usr/bin/php8.2 artisan db:seed

# 4. 创建超级管理员（不要用 make:filament-user）
/usr/bin/php8.2 artisan make:super-admin

# 5. 存量库补建商户默认角色（幂等，新库可跳过）
/usr/bin/php8.2 artisan merchants:provision-roles

# 6. 生产优化缓存
/usr/bin/php8.2 artisan filament:upgrade
/usr/bin/php8.2 artisan config:cache
/usr/bin/php8.2 artisan route:cache
/usr/bin/php8.2 artisan view:cache
/usr/bin/php8.2 artisan storage:link    # 如使用 public 磁盘

# 7. 权限归位（部署过程可能产生非 www-data 文件）
sudo chown -R www-data:www-data /var/www/sscpay-server
sudo chmod -R ug+rwx /var/www/sscpay-server/storage /var/www/sscpay-server/bootstrap/cache

# 8. 拉起 Worker + 配置 cron（第 4、5 节）
sudo supervisorctl reread && sudo supervisorctl update
sudo crontab -u www-data -e
```

---

## 9. 日常自动部署

已有站点以仓库根目录的 [deploy.sh](../deploy.sh) 作为日常上线入口。首次使用前将脚本所在版本部署到服务器，确认站点运行账号能拉取 Git、写入项目目录，并已按前文配置 `.env`、原有 `APP_KEY`、数据库、Redis 和插件存储。服务器需具备 Git、Composer、符合第 1 节要求的 Node/npm 和 `flock`（Linux 的 util-linux），PHP 还需安装 ZIP 扩展。

```bash
cd /var/www/sscpay-server

# 自动识别账号并检查权限，检查通过后再正式部署。
bash deploy.sh --check
bash deploy.sh

# 多 PHP 版本时指定实际 CLI；Composer 也使用所选 PHP 执行。
DEPLOY_PHP=/usr/bin/php8.3 bash deploy.sh

# CI 已检出目标提交时，跳过 Git 拉取，其他上线步骤完整执行。
bash deploy.sh --no-pull
```

非 root 启动时沿用当前账号。root 启动时，脚本依次从本站 `storage/`、日志/框架目录与 `bootstrap/cache/` 的非 root 属主、项目目录与 `.env` 的非 root 属主中识别账号；两级文件归属均无候选时，才参考 PHP-FPM worker 进程。每一级必须得到唯一 UID，多账号冲突会列出来源并停止；无证据或 UID 没有对应系统账号时也会停止。优先使用本站目录证据，避免仅根据另一站点的全局 PHP-FPM 进程选择账号。特殊混合属主环境仍可用 Shell 环境变量 `DEPLOY_USER` 显式覆盖。

选中账号后，通过 `runuser` 切换执行；缺少 `runuser` 时使用 `sudo -n -H`。保留原参数、`PATH`、`DEPLOY_PHP` 与 `DEPLOY_COMPOSER`，全部部署命令使用该账号执行；非 root 启动但与显式 `DEPLOY_USER` 不一致时停止。账号和切换工具检查均在创建部署锁、进入维护模式及执行 Composer 前完成。Composer 在 root 非交互执行时会禁用插件，说明见 [Composer 官方文档](https://getcomposer.org/root)。

权限预检在切换后的实际账号下执行：`.env`、部署脚本和 PHP 自动加载文件必须可读；源码的目录链和 Git 目录必须可读、可写、可进入；依赖树检查目录可操作且文件可读；框架缓存、日志、插件构建/本地发布及前端生成目录还会检查已有文件可写。检查覆盖 Git 公共目录、已有部署锁和嵌套目录，缺失目录检查最近的已有父目录，错误输出具体路径。`.env` 仅需读取，Git 对象及依赖文件可保持只读；运行目录中的 `.gitignore` 也仅需读取。此前 root 运行产生的属主问题按第 2 节核对，预检只报告问题，不修改属主、模式或 ACL。

前端工具在切换账号后选择：显式 `DEPLOY_NODE_BIN` 优先，否则依次检查本站 `storage/app/deploy-tools/node/bin`、共享 `/opt/sscpay-node/bin`，均未配置时使用原 PATH。所选目录必须同时提供可执行的 `node` 与 `npm`，脚本将其加入本次部署的 PATH 开头并打印实际路径。Node 版本在维护模式前按 `^20.19.0 || >=22.12.0` 检查，与 `package.json` / 锁文件的根 `engines.node` 保持一致；不兼容版本直接停止，安装依赖另加 `--engine-strict`。调整最低版本时同步维护三处声明及 `check_node_runtime()`。

npm 缓存固定为本站 `storage/app/deploy-cache/npm`，通过大小写两种 npm 配置环境变量覆盖原缓存位置，避免站点账号依赖不可写的 HOME 缓存。预检检查缓存目录、索引及日志可写，允许 `_cacache/content-v*/` 中的内容文件保持只读；正式部署在维护模式前创建缓存目录，`--check` 只检查而不创建。该目录由现有 `storage/app/.gitignore` 忽略，不随 Git 提交。

`--check` 执行上述预检及 Git 工作区/上游分支检查后退出，不创建部署锁、拉取代码、进入维护模式、执行迁移或发布文件；Git 状态检查关闭可选索引锁。正式部署也会自动执行同样的预检，失败时站点保持原访问状态。预检反映当前文件系统权限，后续磁盘空间、外部存储或并发变更造成的失败仍按部署错误处理。

脚本先检查工作区干净并取得本机部署锁，使用维护模式保护更新窗口，通过 `git pull --ff-only` 更新当前分支；随后安装生产 PHP 依赖、构建前端、更新 Filament 资源并缓存当前环境配置。插件协议检查与 `plugins:sync --dry-run` 在迁移前完成，提前发现协议遗漏、构建错误、版本冲突或降级；之后执行 `migrate --force`、路由/视图缓存、`plugins:sync`、`queue:restart`，全部成功才恢复站点访问。`filament:upgrade` 会清除配置、路由和视图缓存，因此这些最终缓存必须在它之后生成。

`plugins:sync` 自动处理 `PluginCatalog` 已接入的全部插件，当前为 WordPress PayPal、信用卡；发布使用与下载页面相同的 `PLUGIN_RELEASE_DISK`。所有包先构建并完成版本预检，再逐个发布；相同版本和内容可重复执行，保留原发布时间，缺失的索引或 ZIP 可由相同源码补齐。各插件按独立稳定入口发布，OSS 写入失败时命令返回失败；跨插件切换不提供事务。预检读取存储并在本机构建安装包，不会试写 OSS，写权限或上传故障仍会在实际发布时阻止恢复站点。

任一步骤失败均返回非零退出码；进入维护模式后的失败会保留维护状态，并输出失败阶段。修复后重新执行 `bash deploy.sh`，迁移和相同内容的插件发布可重试；脚本不自动回滚数据库，也不覆盖未提交代码、不复制 `.env`、不重新生成密钥或执行种子。多实例部署需由部署平台统一调度并使用共享插件存储，本机 `flock` 只串行化同一项目目录的部署。

后续功能若增加上线必需操作，须同步接入此脚本或其调用的 Artisan 命令，保证能重复运行，并补充回归和本节说明。GitHub Release 及原仓库过渡镜像继续由下文的 tag 工作流负责；日常部署直接维护本站下载与更新入口。

- `queue:restart` 对**所有** Worker 生效，依赖 Redis 连通性；若 Redis 认证失败（如 `WRONGPASS`）则不生效，此时改用 `sudo supervisorctl restart sscpay-server-worker-fast:* sscpay-server-worker-slow:*`。
- `--max-time` 是兜底：即使漏跑 `queue:restart`，Worker 也会在运行满设定秒数后自动重启加载新代码。

### 登录账号与部署账号使用不同的 Node

root 执行 `node -v` 不能代表站点账号所用的版本。例如 root 的 `/root/.nvm/versions/node/v22.23.4/bin/node` 与 `www-data` 的 `/usr/bin/node`（18.x）是两套环境，22.23.4 已满足构建要求。将既有 Node 与配套 npm 放到站点可访问的共享目录即可；下面以首次设置 `/opt/sscpay-node` 为例，在服务器用 root 执行，来源路径按实际 nvm 版本调整：

```bash
(
    set -e
    deploy_node_source=/root/.nvm/versions/node/v22.23.4
    deploy_node_target=/opt/sscpay-node
    if [ -e "$deploy_node_target" ] || [ -L "$deploy_node_target" ]; then
        echo '共享目录已存在，请先核对其中的 Node/npm，避免覆盖现有运行时。' >&2
        exit 1
    fi
    install -d -m 755 "$deploy_node_target/bin" "$deploy_node_target/lib/node_modules"
    install -m 755 "$deploy_node_source/bin/node" "$deploy_node_target/bin/node"
    cp -a "$deploy_node_source/lib/node_modules/npm" "$deploy_node_target/lib/node_modules/"
    ln -s ../lib/node_modules/npm/bin/npm-cli.js "$deploy_node_target/bin/npm"
    ln -s ../lib/node_modules/npm/bin/npx-cli.js "$deploy_node_target/bin/npx"
    chmod -R a+rX,go-w "$deploy_node_target"
)
```

随后同步新版脚本，在项目目录执行 `bash deploy.sh --check`，确认打印的 Node 是共享目录中的 22.23.4，npm 路径也来自同一目录，再执行 `bash deploy.sh`。脚本会自动选择共享目录，后续无需每次指定 `DEPLOY_NODE_BIN`，也不需要给站点账号开放 root 家目录。若前一次失败已留下维护模式，完成整次部署后才会自动恢复访问。

### 收款页报错：Vite manifest 缺少 checkout.js

如果出现 `Unable to locate file in Vite manifest: resources/js/checkout.js`，表示服务器上的 manifest 没有收款页入口。当前 `vite.config.js` 已配置该入口；应确认部署的是最新代码，并重新构建配套资源，无需修改 `.env`。

```bash
cd /var/www/sscpay-server
node -v                                 # 需满足上方 Node.js 版本要求
npm ci --include=dev                     # Vite 等构建工具位于 devDependencies
npm run build                           # 成功后再继续发布
node -e 'const fs = require("node:fs"); const m = JSON.parse(fs.readFileSync("public/build/manifest.json", "utf8")); const e = m["resources/js/checkout.js"]; if (!e || !fs.existsSync("public/build/" + e.file)) { throw new Error("收款页构建产物缺失"); } console.log(e.file);'
```

`public/build` 被 Git 忽略，单独 `git pull` 不会更新它。若服务器不安装 Node.js，在 CI 或本地针对同一版本代码构建，然后将 **整个 `public/build` 目录**随本次版本部署（包含 manifest、JS、CSS、动态加载模块和图片）。只上传 manifest 或仅清理 Laravel 缓存无法补齐构建产物。多实例部署需要每个实例使用同一份产物。

---

## 10. 上线检查清单

- [ ] `.env`：`APP_ENV=production`、`APP_DEBUG=false`、`APP_KEY` 已生成且与生产一致
- [ ] `QUEUE_CONNECTION=redis` / `CACHE_STORE=redis` / `SESSION_DRIVER=redis`
- [ ] **`REDIS_QUEUE_RETRY_AFTER=1900`**（> 慢车道 `--timeout=1800`）
- [ ] `storage/`、`bootstrap/cache/`、`storage/app/backups/` 属主为 `www-data` 且可写
- [ ] `crontab -u www-data -l` 含且仅含 `schedule:run`（**不要**放 `schedule:finish`，裸跑会报 `missing: "id"`，见 4.1）
- [ ] `sudo -u www-data php artisan schedule:list` 列出全部 8 个调度任务
- [ ] `sudo supervisorctl status` 两池均 `RUNNING`，且 `user=www-data`
- [ ] `mysqldump` 在 cron 的 `PATH` 内，`db:backup:upload` 手动跑一次能上传到 OSS
- [ ] `/etc/logrotate.d/sscpay-server` 已配置（`copytruncate` + `su www-data www-data`）
- [ ] Nginx `root` 指向 `public/`，`.env` / `.git` 被 deny
- [ ] `deploy.sh` 已成功执行；Filament 资源更新后已重新缓存配置、路由及视图
- [ ] 健康检查 `GET /up` 返回 200
- [ ] 后台 `/admin` 能用超级管理员登录；`GET /sync/products/{paymentMethod}` 调试入口已按 README「12.10」移除或加权限中间件
- [ ] `failed_jobs` 表存在，监控失败任务：`php artisan queue:failed`

---

## 电商插件发布与更新

WordPress PayPal / 信用卡的维护源在 `plugins/wordpress/`，OpenCart 3 / 4 的维护源在 `plugins/opencart/`（每代一个安装包，内含 PayPal 与信用卡）；原 WordPress 独立仓库保留作为旧安装的兼容分发入口。本项目的主页链接到 `/plugins`，更新检查接口为 `GET /plugins/updates/v1/{slug}`，下载地址为 `GET /plugins/{slug}/{version}/download`。更新服务的 `v1` 是元数据接口版本，与支付协议 `2.0` 分开编号。接口公开只读，不要求商户 API 密钥；安装包从私有磁盘流式下载，保留 SHA-256 校验。

### 本地构建与验收

```bash
php artisan plugins:check --no-interaction
php artisan test --compact tests/Unit/WordPressPaymentProtocolTest.php tests/Feature/PluginDistributionTest.php tests/Feature/WordPressV2ContractTest.php
php artisan plugins:build sscpay-payment-paypal --no-interaction
php artisan plugins:build sscpay-payment-creditcard --no-interaction
php artisan plugins:build sscpay-opencart3 --no-interaction
php artisan plugins:build sscpay-opencart4 --no-interaction
php artisan test --compact tests/Unit/OpenCartPaymentProtocolTest.php tests/Feature/OpenCartGatewayTest.php tests/Feature/OpenCartV2ContractTest.php tests/Feature/OpenCartDistributionTest.php
```

安装包和 `release.json` 输出到 `storage/app/plugin-builds/{slug}/{version}/`，不会打包 Laravel 源码、配置、Git 文件或测试。WordPress 文件列表固定为插件主文件、`index.php`、`includes/`、`assets/`、`languages/`；OpenCart 3 打包 `upload/` 与 `install.json`，4 打包 `admin/`、`catalog/`、`install.json`，两者注入白名单共享客户端、模板及版本文件。共享代码使用 PHP 7.4 语法，OpenCart 4 运行时至少 PHP 8.0；Laravel Pint 排除整个插件目录，CI 以 PHP 7.4 / 8.0 / 8.3 检查语法。

`plugins:publish {slug}` 会构建并上传单个插件；`plugins:sync` 会预检并发布全部已接入插件，已纳入日常部署脚本。两者都使用 `config/plugins.php` 指定的磁盘。未配置 `PLUGIN_RELEASE_DISK` 时为本地私有 `local`，文件实际位于执行机器的 `storage/app/private/plugin-releases/`，必须在对应服务器发布并持久保存该目录；多实例及 GitHub Actions 发布使用 `PLUGIN_RELEASE_DISK=oss`，与运行应用对齐 bucket/prefix。版本 ZIP 和清单均写入成功后才更新该插件的 `stable.json`；同一版本不同内容拒绝覆盖，旧版本不能替换更新的稳定版本。下载页始终从已发布记录读取版本；单独 `git pull` 或 `plugins:build` 不会创建线上发布记录。

### GitHub Actions 配置

`.github/workflows/plugins.yml` 检查协议指纹、四个安装包版本递增、插件行为及服务端 v2 回归；建议将 `verify` 与 `php-compatibility` 设为分支合并必需检查。发布工作流只接受 `plugins/{slug}/v{version}`，如 `plugins/sscpay-payment-paypal/v1.1.0` 和 `plugins/sscpay-payment-creditcard/v1.1.0`，新增 OpenCart 使用 `plugins/sscpay-opencart3/v1.0.0` / `plugins/sscpay-opencart4/v1.0.0`；每个包各打一个 tag。

GitHub 仓库配置：

| 类型 | 名称 | 用途 |
|---|---|---|
| Secret | `PLUGIN_OSS_ACCESS_KEY_ID`、`PLUGIN_OSS_ACCESS_KEY_SECRET` | 发布账号，仅需插件对象前缀的读写权限 |
| Variable | `PLUGIN_OSS_BUCKET`、`PLUGIN_OSS_ENDPOINT`、`PLUGIN_OSS_PREFIX` | 与应用运行时 OSS 的 bucket、endpoint、prefix 对齐；CI 使用可从 GitHub 访问的公网 endpoint |
| Secret（兼容镜像可选） | `LEGACY_PLUGIN_RELEASE_TOKEN` | 可写两个原插件仓库 Release 的凭证，仅用于 CI |
| Variable（兼容镜像可选） | `LEGACY_PLUGIN_OWNER` | 原插件仓库所属账号或组织；仓库名分别为两个插件 slug |

推送 tag 后，工作流先检查对应 tag 的代码，生成 Actions 安装包附件，发布 OSS 稳定版本，再将安装 ZIP 与清单附到本仓库 Release。已配置兼容镜像时，把同一 ZIP 附到原仓库的 `v{version}` Release；原仓库只承担旧更新器的分发，不再作为新源码依据。GitHub 自动生成的整仓库 Source ZIP 不能作为插件安装包。发布工作流支持手动指定已有 tag 重试，整个发布任务串行执行，避免不同版本同时切换稳定入口。

### 首次迁移

1. 先部署下载与更新服务，再发布新插件包，并验证运行地址的元数据和 ZIP 可访问。
2. 将过渡版本的同一安装 ZIP 发布到原 PayPal / 信用卡仓库 Release。旧站点仍通过已有 GitHub 更新器升级；过渡版本启用后改为读取本项目。
3. 新更新器默认使用生产 API 根地址，留空时使用沙箱根地址；可在插件设置填写独立 HTTPS 更新服务根地址，或通过 `SSCPAY_UPDATE_BASE_URL` / 对应插件的 `SSCPAY_PAYPAL_UPDATE_BASE_URL`、`SSCPAY_CREDITCARD_UPDATE_BASE_URL` 覆盖。更新过程不发送支付 API Key 或 GitHub Token。
4. 升级保留原 slug、主文件、支付方式 ID、配置和订单引用。不要删除其他插件仍在使用的共用 GitHub Token。原仓库过渡 Release 保留，便于长期未升级的站点接入。

### OpenCart 安装、配置与升级

| 安装包 | 商城范围 | 插件 PHP 下限 | ZIP 结构 / 文件名 |
|---|---|---|---|
| `sscpay-opencart3` v1.0.0 | 3.0.x | 7.4 | `upload/`，下载 `sscpay-opencart3.ocmod.zip` |
| `sscpay-opencart4` v1.0.0 | 4.0.x / 4.1.x | 8.0（同时满足商城要求） | 根目录 `admin/`、`catalog/`、`system/`、`install.json`，文件名必须为 `sscpay.ocmod.zip` |

1. 在 OpenCart「扩展 → 安装器」上传对应包；4.x 再执行包的安装，然后在支付扩展中分别安装 `SSCPay PayPal`、`SSCPay Credit Card`，保存配置后启用需要的支付方式。使用默认结账入口；不支持订阅、循环扣款或无需商品记录的纯礼品券订单。
2. 每种支付方式分别填写 HTTPS API 根地址、App-ID、API Key、支付组和可选的指定渠道。后台密钥留空保留已保存值，不回显；默认平台标识 `opencart` 必须已在 SSCPay 的 `order.platforms` 中启用。不要使用带 `/api` 的根地址。配置通过 OpenCart 标准 setting 模型写入默认店铺，其他店铺继承时仍按 `store_id` 隔离订单和回调。
3. 配置状态映射：全额付款选择系统「处理中 / 完成」分类之一，其他映射必须属于非已付款分类；建议新建独立部分付款、退款和拒付状态。部分付款不减为零、不触发付款完成；过期但已付部分款项保留进度，不再引导继续付款；组合订单的售后 review 保留曾付款事实并显示远端状态，需人工处理。
4. 支付通知地址由客户端随建单发送。需要物流回传时，在 SSCPay 应用中填写插件设置显示的完整 shipment 地址，如 `https://商城域名/index.php?route=extension/payment/sscpaypaypal&sscpay_action=shipment`（3.x）或 `https://商城域名/index.php?route=extension/sscpay/payment/sscpaypaypal&sscpay_action=shipment`（4.x）；信用卡把末段换成 `sscpaycreditcard`。多店铺使用实际店铺域名。回传仅追加配送备注与去重记录，不改变付款状态。
5. 插件设置中的订单工具输入 OpenCart 订单 ID 可只读查询远端进度；商城状态由签名通知或持有不可猜回跳 key 的主动查询更新。发货填写 SSCPay 已登记的承运商代码、运单号及带时区 ISO 8601 时间。组合订单全额付清后逐个向真实已付款子单发送，同一请求重试跳过已确认成功的子单。
6. 可填写独立 HTTPS 下载站根地址并「检查新版本」，不发送支付密钥；返回版本、下载地址和 SHA-256。升级前备份，3.x 重新上传安装包；4.x 将 ZIP 根目录内容覆盖到 `extension/sscpay/`，不先卸载或删除支付记录。4.x 安装器通过 ZIP 文件名识别扩展代码，因此下载后不可改名；GitHub 自动生成的源码 ZIP 不能安装。

OpenCart 建单商户号固定为 `OC{3|4}-{store_id}-{order_id}`；第一次请求前持久保存，超时后沿用原号补单，跨支付方式 / 应用 / API 地址的复用被拒绝。卸载支付方式保留对账记录；不在卸载时删除扩展表。共享客户端修改后应重测并递增两个 OpenCart 包版本。

CI 另在独立 MySQL 8 临时库验证建表、事务回滚、nonce 防篡改及同单四并发回调。手工运行 `OpenCartStorageTest` 时显式提供 `OPENCART_TEST_MYSQL_DSN=mysql:host=127.0.0.1;port=3306`、`OPENCART_TEST_MYSQL_USER`、`OPENCART_TEST_MYSQL_PASSWORD`；测试自行创建并删除随机 `sscpay_oc_test_*` 库，完全不读取应用数据库配置。未配置时该专项跳过。协议契约测试仍使用隔离 SQLite 内存库。

当前验证覆盖协议、打包后入口、MySQL 存储及本机 3.0.3.8 的模板运行时；完整 OpenCart 3/4 安装、4.x 实际运行、主题/第三方结账、库存/优惠券等核心扩展联动及真实收款需在专用商城测试站验收。退款与拒付使用主动查询同步已有状态，本插件不提供真实退款发起动作。

### 插件开发与 API 同步规范

商户 API 与已接入商城插件在同一开发任务内交付。当前同步范围是 WordPress PayPal、信用卡及 OpenCart 3、4 四个安装包；后续平台接入后纳入相同流程。协议定义继续维护在 [API 手册](api.md)，本节只规定开发和交付约束。

| 范围 | 开发约定 |
|---|---|
| 源码与职责 | 以本仓库 `plugins/{platform}/{slug}/` 为维护源，原独立仓库仅作兼容分发。插件独立安装运行，不依赖 Laravel 的 `vendor/` 或另一个 SSCPay 支付插件已启用；只负责商城数据转换、API 调用、结果验证与商城状态映射，汇率、渠道路由及账务由服务端处理。 |
| 运行环境 | 每个平台声明并验证自己的商城、PHP 与 API 兼容版本。WordPress 与 OpenCart 共享客户端保持 PHP 7.4 语法，OpenCart 4 运行时至少 PHP 8.0，沿用相邻文件的 WordPress 风格，与 Laravel 服务端分别格式化、检查。 |
| 升级兼容 | 保留现有 slug、主文件、支付方式 ID、配置键、订单 meta 和已公开钩子的语义。保留目前支持的 V1 / V2 格式；不兼容变更需有服务端兼容期和升级迁移安排，不能假设所有商户会立即升级。 |
| 签名与数据 | 请求和回调遵循同一规范化签名算法，先对完整报文验证再转换字段。金额保持十进制字符串，不以浮点数判断已付金额；日志和更新请求不暴露 API Key、GitHub Token 等凭证。 |
| 支付与物流 | 同一订单重试沿用原商户号和已保存引用；事件去重、进度版本只前进，只有原订单付清才确认商城付款。按真实子单上传物流，按父商户号与运单号去重回传，不由物流回传改变支付状态。 |
| 测试 | 使用实际服务端报文验证每个已接入插件，覆盖完整验签、错误身份和金额、重复与乱序、部分与全部付款、同号重试及物流去重；发布检查覆盖包结构、版本和下载校验。变更行为时补充相应失败场景。 |
| 版本与安装包 | 插件版本独立于 Laravel 版本，使用三段版本号：缺陷修复递增修订号，兼容新增功能递增次版本，不兼容升级递增主版本。WordPress 头部 `Version`、版本常量与 `plugin.json.version` 一致；OpenCart 的 `install.json.version` 与清单一致，后台显示的版本由打包时注入，更新说明写清 API 变化与兼容范围。已发布版本包保持不可变。 |

每次涉及请求字段、响应字段、错误码、签名、付款通知、查询状态或物流协议变化，按以下顺序完成：

1. **确认影响。** 核对执行代码、API 文档和相关测试，逐个检查已接入插件的调用、验签、状态处理及数据保存。当前四个安装包都须给出兼容性结论；无需修改运行逻辑时说明原因，不为制造代码差异改写原有行为。
2. **同步适配。** 在本次任务中完成服务端与插件修改，保留旧订单、旧配置和兼容协议。字段新增也要检查完整体验签、金额类型和状态分支，不能仅凭 HTTP 200 判定已收款。
3. **补充并运行回归。** 更新相关插件测试与 [WordPress 契约测试](../tests/Feature/WordPressV2ContractTest.php)、[OpenCart 契约测试](../tests/Feature/OpenCartV2ContractTest.php)，验证旧格式与本次变化；新增行为的用例同时覆盖成功和重要失败路径。真实商城安装、升级或 MySQL 并发未验证时在交付记录中注明。
4. **同步版本与协议声明。** 当前指纹规则要求 API 关联文件变化后四个包都递增版本，维护各自版本值、`api_version` 和更新说明。格式化与协议文档更新完成后，执行 `php artisan plugins:check`，按输出的当前 SHA-256 更新各自 `plugin.json.protocol_hash`，然后重新检查；不能仅改版本或指纹代替适配与测试。
5. **检查并构建。** 执行本节的本地构建与验收命令。开发中可用 `php artisan plugins:check --base=HEAD` 比较未提交修改；CI 使用相应基线提交检查全部插件升版。确认四个安装包可独立构建，并核对 ZIP 内目录、版本与校验值。
6. **交付与发布。** 一起报告服务端变化、各插件版本、兼容性结论、验证结果和安装包位置，明确是否已经发布。版本准备、构建完成与生产发布分别记录；按兼容性安排部署服务端及发布插件，不将“API 已完成、插件尚待适配”作为默认交付状态。

**检查范围与能力边界：** [PluginProtocol::files()](../app/Support/PluginProtocol.php) 当前按登记文件的完整内容计算指纹，命中的文案或格式变化也会触发同步检查。指纹只能证明登记内容与插件声明对应，语义正确性仍由代码适配、回归和审查确认。新增协议实现文件或电商平台时，一并扩充插件目录发现、指纹覆盖、参数化契约测试、运行环境检查和发布工作流；下载页出现平台名称不代表插件已接入。CI 检测不一致并执行测试，不会自动重写插件业务代码。开发任务中的同步由开发者或 AI 完成；插件发布后的商户站安装升级遵循对应平台方式：WordPress 使用后台更新；OpenCart 使用版本检查和安装包升级，当前不在线自动覆盖文件。

V2 使用原建单、查单和发货路径；使用组合付款时，清空插件的「指定支付渠道」，并在对应支付组开启组合付款。已有指定渠道配置不会自动清除，指定渠道下单继续原语义。组合付款按父单 `event_id` / `version` 处理，只有付清父单才确认 WooCommerce 付款完成。商城物流集成可调用插件网关的 `sync_shipping($order, $shipment)`，按真实子单上传同一运单，不向父单上传。更完整的协议定义继续以 [API 手册](api.md#组合付款与-v2-通知) 为准。

如启用应用的「物流回传」，将完整接收地址设置为插件 WooCommerce 配置页显示的地址，通常为商户站的 `/wc-api/sscpay_shipment/`。该入口兼容两个支付方式，按商城订单实际网关的凭据对完整 body 验签；组合子单使用父商户号与父系统号定位，并按原商户号＋运单号去重。配送记录保存为 `_sscpay_shipments` 及订单备注，不改变付款或完成状态；需要同步到其他物流扩展时接入实际支付方式的 `sscpay_paypal_shipment_received` / `sscpay_creditcard_shipment_received` 钩子。

## 11. 常见坑与排查

| 现象 | 原因 | 处理 |
|---|---|---|
| 商品同步任务反复从头开始 / 被并发执行两次 | `REDIS_QUEUE_RETRY_AFTER`（默认 90）< 慢车道 `--timeout`（1800），任务没跑完就被判定丢失重新派发 | 设 `REDIS_QUEUE_RETRY_AFTER=1900`，重启 Worker |
| WooCommerce 同步每次约 60 秒就被杀 | `--timeout` 用了默认 60，覆盖不了 18 分钟最坏耗时 | 慢车道 `--timeout=1800`，`stopwaitsecs=1830` |
| 部署后队列仍跑旧代码 | 常驻 Worker 未重启 | `php artisan queue:restart`；Redis 不通时用 `supervisorctl restart` |
| `storage/` 报权限错误 / 日志写不进 | 曾用 root 跑 artisan；或 logrotate 用了 `create` 而非 `copytruncate`，Monolog 仍写旧 inode 导致新日志文件为空 | `chown -R www-data:www-data storage bootstrap/cache`；logrotate 用 `copytruncate` + `su www-data www-data` |
| `db:backup:upload` 失败，日志 `mysqldump failed` | cron 精简 `PATH` 找不到 `mysqldump`，或 DB 凭证 / 网络不通 | 确认 `mysqldump` 在 `PATH`（或写绝对路径），核对 `.env` DB 配置 |
| OSS 备份 SSL 连接超时 | 生产误用了仅内网可达的 Endpoint，或本地误用了内网 Endpoint | 生产（阿里云 ECS 同地域）可用内网 Endpoint；跨网络环境用公网 Endpoint |
| 定时任务不按预期时间跑 | 服务器时钟漂移，或 `APP_TIMEZONE` 与预期不符 | 开启 NTP 校时；`config/app.php` 默认 `PRC`，按需在 `.env` 设 `APP_TIMEZONE` |
| `queue:restart` 无效 | Redis 认证失败（`WRONGPASS`），时间戳写不进 cache | 核对 `REDIS_PASSWORD`；临时改用 `supervisorctl restart` |
| 后台/API 在错误域名下仍可访问 | `FILAMENT_PLATFORM_DOMAIN` / `FILAMENT_MERCHANT_DOMAIN` / `API_DOMAIN` 未配置（留空即不限制） | 按实际域名填写，见 README「11.3」 |

---

> 相关文档：README「11. 环境要求与部署」、「9. 异步任务与定时调度」；`doc/wordpress-integration.md`（插件对接）；`doc/s-system-payment-status-notify.md`（支付回调）。

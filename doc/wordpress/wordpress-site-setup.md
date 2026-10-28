# WordPress 独立站建站流程（SSCPay 商户站）

> 适用范围：为一个新商户搭建「WordPress + WooCommerce 独立站」，并接入 SSCPay 收款。
> 文中所有**路径、命令、字段名**均取自本机 `sscpay-server` / `wordpress` 两套代码的实测结果；
> 凡属「生产建议、本地未验证」的段落会显式标注。
>
> 配套文档：
> - 支付状态协议：[s-system-payment-status-notify.md](s-system-payment-status-notify.md)
> - 物流同步协议：[s-system-sync-tracking.md](s-system-sync-tracking.md)

---

## 0. 全流程总览

```
① 基础设施          ② WordPress 落地        ③ 主题与商城
域名/证书/nginx ──▶ 核心文件/wp-config ──▶ 主题 + WooCommerce 页面
                                                        │
                                                        ▼
⑥ 验收 ◀── ⑤ 本地 HTTPS 互信 ◀── ④ SSCPay 支付接入 ◀──┘
                          （仅本地开发需要）
```

| 阶段 | 关键产出 | 失败时的典型现象 |
| --- | --- | --- |
| ① 基础设施 | 域名可达、HTTPS 正常、PHP 能执行 | 502、白屏、证书告警 |
| ② WordPress 落地 | 后台可登录 | DB error 2002、install.php 引导页 |
| ③ 主题与商城 | 商品/购物车/结算页可用 | 结算页 404、模板缺失 |
| ④ SSCPay 支付接入 | 下单返回 200 并跳转收银台 | 支付方式不显示、401/404/500 |
| ⑤ 本地 HTTPS 互信 | PHP 侧信任本地证书 | cURL error 60 |
| ⑥ 验收 | 清单逐条打勾 | —— |

**本机已验证的环境基线**（2026-10-21 实测）

| 组件 | 版本 / 取值 | 位置 |
| --- | --- | --- |
| PHP | 8.3.35（cli 与 fpm 同源） | `/opt/homebrew/opt/php@8.3/bin/php` |
| php-fpm | 监听 `127.0.0.1:9000`，以 `_www` 运行 | `/opt/homebrew/etc/php/8.3/php-fpm.d/www.conf` |
| MySQL | 8.0.46（Homebrew） | DB `wordpress` / 用户 `wpuser` |
| nginx | brew 版，站点在 `servers/*.conf` | `/opt/homebrew/etc/nginx/servers/` |
| WordPress | 7.1.3 | `/Users/akilau/sites/wordpress` |
| WooCommerce | 已装，页面 ID 498(shop)/544(cart)/545(checkout) | 货币 USD |
| WP-CLI | 全局安装 | `/opt/homebrew/bin/wp` |
| 主题 | `toystore`（另有 astra / blocksy / twentytwenty*） | `wp-content/themes/` |
| SSCPay 插件 | 4 个，各自独立 git 仓库 | `wp-content/plugins/sscpay-*` |

---

## 阶段一：基础设施

### 1.1 运行时三件套

```bash
brew install php@8.3 mysql nginx
brew services start php@8.3 mysql nginx
```

PHP 扩展需求（WordPress + WooCommerce 常规）：`pdo_mysql`、`mysqli`、`curl`、`openssl`、`mbstring`、`zip`、`gd`、`exif`、`imagick`（可选）、`intl`、`bcmath`。

```bash
/opt/homebrew/opt/php@8.3/bin/php -m | grep -E "pdo_mysql|mysqli|curl|openssl|mbstring|zip|gd|intl|bcmath"
```

### 1.2 域名与证书

- **生产**：域名 A 记录指向服务器公网 IP，签发 Let's Encrypt 证书（`certbot --nginx -d shop.example.com`），证书需覆盖 `www` 与裸域。
- **本地**：使用 `mkcert` 生成自签证书，根 CA 位于 `~/Library/Application Support/mkcert/rootCA.pem`。
  本机现有证书：`~/.cert/wordpress-local-cert.pem`（站点 `www.wordpress.local`）与
  `~/.cert/sscpay-local.pem`（订单系统 `www.ssecurepay.local`），两者由同一 mkcert CA 签发。

> ⚠️ mkcert 根 CA 只在这台机器上有效，**换机器、换容器都会失效**；本机以外的调用方
> （如另一台服务器回调本机）需要单独安装根 CA 或改用公网证书。

### 1.3 nginx 站点配置

本机 WordPress 站点的配置（`/opt/homebrew/etc/nginx/servers/wordpress.conf`）：

```nginx
server {
    listen 80;
    server_name www.wordpress.local wordpress.local;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name www.wordpress.local wordpress.local;
    root /Users/akilau/sites/wordpress;
    index index.php index.html;

    client_max_body_size 100m;

    ssl_certificate     /Users/akilau/.cert/wordpress-local-cert.pem;
    ssl_certificate_key /Users/akilau/.cert/wordpress-local-key.pem;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$script_name;
        include fastcgi_params;
    }
}
```

要点：
1. `try_files $uri $uri/ /index.php?$args;` —— WordPress 伪静态必须，否则 404。
2. `fastcgi_param SCRIPT_FILENAME $document_root$script_name;` —— 路径写错会 404 或空白页。
3. 订单系统站点（`www.ssecurepay.local`）是**另一个** server 块，root 指向 `sscpay-server/public`，
   两者不要写进同一个 server 块。
4. 生产环境建议补一条敏感文件保护（本地配置未加，实测 WP 根目录下无 `wp-config.php` 之外的可读敏感文件风险敞口，但生产应加）：

```nginx
    location ~* /(wp-config\.php|\.htaccess|\.git|readme\.html|license\.txt) {
        deny all;
    }
```

### 1.4 ⚠️ php-fpm 池隔离

本机 `wordpress.local` 与 `ssecurepay.local` **共用同一个 fpm 池**（`127.0.0.1:9000`）。
这意味着：

- 重启 `php@8.3` 会同时影响两个站；
- 两个站共享同一份 PHP 扩展、同一份 `php.ini`、同一个 opcache；
- 任何一个站的 `max_execution_time` / `memory_limit` 调整会同时生效。

生产多站场景建议为每个站建独立池（`php-fpm.d/<site>.conf`，各自 `listen = 127.0.0.1:900X`，
并用 `pm = static` 隔离进程数）。

---

## 阶段二：WordPress 落地

### 2.1 放核心文件

```bash
# 本机已有 wordpress-7.1.3.zip，可直接复用
unzip -q /Users/akilau/sites/wordpress-7.1.3.zip -d /tmp/wpcore
cp -R /tmp/wpcore/wordpress/. /path/to/newsite/
```

### 2.2 建库建号

```bash
mysql -uroot -e "CREATE DATABASE wordpress DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wpuser'@'localhost' IDENTIFIED BY 'Wp@123456';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wpuser'@'localhost'; FLUSH PRIVILEGES;"
```

> 密码务必在生产改为强随机值，并通过部署平台注入，不要写进版本库。

### 2.3 wp-config.php

从 `wp-config-sample.php` 复制后至少要改这四项：

```php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wpuser' );
define( 'DB_PASSWORD', 'Wp@123456' );
define( 'DB_HOST', 'localhost' );
```

本机站点还有两处状态值得注意（`wp-config.php` 实测）：

- `WP_DEBUG` 为 `false`（生产合理；本地排查时临时打开并配 `WP_DEBUG_LOG`）。
- `WP_HOME` / `WP_SITEURL` **被注释掉**，即域名由数据库 option `home` / `siteurl` 决定。
  好处是后台改域名即可；坏处是改库失败时站点会跳回旧域名。

生产补充（建议）：

```php
define( 'DISALLOW_FILE_EDIT', true );  // 禁止后台改主题/插件代码
define( 'FS_METHOD', 'direct' );       // 文件系统直写，省去 FTP 凭据
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
```

### 2.4 安装站点（WP-CLI）

```bash
cd /path/to/newsite
wp core install \
  --url="https://shop.example.com" \
  --title="Tinkerpine" \
  --admin_user="admin" \
  --admin_password="<强密码>" \
  --admin_email="ops@example.com" \
  --skip-email
```

本机站点现值：`blogname = Tinkerpine`，`home = siteurl = https://www.wordpress.local`。

### 2.5 权限与可读性

```bash
# 属主与 fpm 运行用户保持一致
chown -R _www:www /path/to/newsite
find /path/to/newsite -type d -exec chmod 755 {} \;
find /path/to/newsite -type f -exec chmod 644 {} \;
```

`wp-config.php` 建议 `640`，并确保 web 根目录没有多余的可下载文件（备份、压缩包、SQL dump）。

---

## 阶段三：主题与商城

### 3.1 主题

```bash
wp theme install toystore --activate
```

- 本机为 `toystore` 主题，另备有 `astra`、`blocksy` 与官方默认主题。
- 装了 Elementor 时**必须用子主题**，否则升级主题会覆盖模板改动。

### 3.2 WooCommerce 初始化

WooCommerce 安装后需要店铺基础页（本机已存在）：

| 页面 | 本机 page_id | 说明 |
| --- | --- | --- |
| 商店 | 498 | 商品列表 |
| 购物车 | 544 | Cart |
| 结算 | 545 | Checkout |
| 我的账户 | —— | 可选，账户型站点才建 |

核对命令：

```bash
wp option get woocommerce_shop_page_id
wp option get woocommerce_cart_page_id
wp option get woocommerce_checkout_page_id
wp option get woocommerce_currency     # 本机 USD
```

必须同步确认：
1. **结算页 URL 必须可公开访问**（未登录也能打开），否则插件下单时拿不到 `return_url`；
2. WooCommerce → 设置 → 高级 → 结算页，确认 WooCommerce 页面在「页面」里存在；
3. 虚拟商品需在商品属性里勾选「虚拟」，否则插件会因为配送地址缺失被拒单。

### 3.3 插件清单

本机已启用（实测）：

```
woocommerce              elementor              cartflows
sscpay-gateway-aggregator sscpay-payment-creditcard
sscpay-payment-paypal    sscpay-products-api
astro-sites               modern-cart            power-coupons
surecookie                sureforms              surerank
variation-swatches-woo    woo-cart-abandonment-recovery
woocommerce-payments（停用）
```

`woocommerce-payments` 保持停用——本项目的收款走 SSCPay 插件，避免两套网关抢单。

---

## 阶段四：SSCPay 支付接入

### 4.1 安装四个插件

四个插件都是独立 git 仓库，克隆到 `wp-content/plugins/` 后启用：

```bash
cd /path/to/newsite/wp-content/plugins
git clone <repo-url> sscpay-gateway-aggregator
git clone <repo-url> sscpay-payment-creditcard
git clone <repo-url> sscpay-payment-paypal
git clone <repo-url> sscpay-products-api
cd /path/to/newsite
wp plugin activate sscpay-gateway-aggregator sscpay-payment-creditcard sscpay-payment-paypal sscpay-products-api
```

> `sscpay-gateway-aggregator`（PGA）是订单系统反向调 WP 收银台的服务端插件；
> 另三个分别是网关聚合客户端、信用卡渠道、PayPal 渠道与商品接口。

### 4.2 平台侧（sscpay-server）准备

在订单系统后台（Filament）完成四件事：

| 步骤 | 后台路径 | 产出 |
| --- | --- | --- |
| ① 建应用 | `/admin/applications/create` | **App-ID**（如 `APP_B7VUP8QZPPYO`）与 **API Key**（只展示一次，插件里是 password 字段） |
| ② 填应用 website | 应用编辑页 | 商户电商站域名，本机为 `https://shop.sscpay.test` |
| ③ 建支付组 | `/admin/payment-groups` | `group_key`，本机为 `group_stripe`（必须 `is_active`） |
| ④ 建支付方式 | `/admin/payment-methods` | `method_code`（本机 `stripe_card`）+ **domain**（本机 `https://www.wordpress.local`）+ 费率 |

> ④ 的 `domain` 不是装饰：下单时若指定了 `payment_method_key`，
> `OrderCreationService::resolveDesignatedPaymentMethod()` 会强制
> `notify_url` / `return_url` / `cancel_url` 三个回跳地址的 host 与之相同，
> 不一致直接抛 `PaymentMethodDomainMismatchException` 拒单。

### 4.3 插件侧配置（WooCommerce → 付款 → SSCPay - 信用卡）

| 插件字段 | key | 本机值 | 说明 |
| --- | --- | --- | --- |
| 沙箱模式 | `testmode` | `yes` | 决定用下面哪一组凭据 |
| 生产环境 API Base URL | `live_base_url` | （空） | **订单系统**地址，不带 `/api`、不带尾部斜杠 |
| 沙箱环境 API Base URL | `sandbox_base_url` | `https://www.ssecurepay.local` | 同上 |
| 生产/沙箱 App-ID | `live_app_id` / `sandbox_app_id` | `APP_B7VUP8QZPPYO` | 与 4.2① 对应 |
| 生产/沙箱 API Key | `live_api_key` / `sandbox_api_key` | （已配置） | password 字段，只展示一次 |
| 支付组 group_key | `group_key` | `group_stripe` | 必填，即使指定了支付方式也必填 |
| 指定支付渠道 | `payment_method_key` | `stripe_card` | 留空则由支付组路由决定 |
| 平台标识 platform | `platform` | `wordpress` | 必须在订单系统 `order.platforms` 枚举内，否则拒单 |
| 商户订单号前缀 | `order_prefix` | `WC` | 多站共用同一商户账号时必须区分，否则订单号撞车 |
| 接口超时 | `timeout` | `30` | 允许 5–120 秒 |

**Base URL 的判定依据**（本地已核对）：

- nginx `ssp.conf` 的 root = `sscpay-server/public`；
- `bootstrap/app.php` 用 `withRouting(api: routes/api.php)` 注册，Laravel 12 自动加 `api` 前缀；
- `artisan route:list --path=api` 实际 URI：
  `api/order/create`、`api/order/query`、`api/order/ship`、`api/webhooks/payment-gateway/status`；
- 插件拼接方式为 `base_url . '/api/order/create'`（`class-sscpay-creditcard-api-client.php:143`），
  且只做 `trim()` + 去尾部斜杠。

⇒ 本地正确值就是 `https://www.ssecurepay.local`（**不要**写 `/api`，写成 `https://www.wordpress.local` 会打到 WP 上 404）。

> ⚠️ `routes/api.php` 外层有 `Route::domain(config('app.api_domain'))`。
> 本机 `.env` **未配置** `API_DOMAIN` → 不限制 host；生产一旦配置，
> Base URL 的 host 必须与之完全一致，否则直接 404。

### 4.4 连通性自检（三步，逐层排除）

```bash
# ① 路由是否命中：预期 401（缺 App-ID/签名），不是 404
curl -s -o /dev/null -w "%{http_code}\n" -X POST https://www.ssecurepay.local/api/order/create \
  -H "Content-Type: application/json" -d '{}'

# ② 完整下单：预期 200，响应里带 payment_url / pay_url
#    用插件的签名算法生成 sign，见 plugin README「调试」章节

# ③ 看插件日志：WooCommerce → 状态 → 日志 → 来源 sscpay_creditcard
```

### 4.5 回调与验签

- 插件把 `notify_url`（`https://<商户站>/wc-api/sscpay_creditcard_notify/`）、
  `return_url`（`/wc-api/sscpay_creditcard_return/`）、`cancel_url` 一并传给订单系统；
- 订单系统只在订单变为 `paid` 时回调，插件验签通过后改订单状态；
- **验签算法不含 URL 路径**：`HMAC-SHA256(App-ID + "\n" + Timestamp + "\n" + X-Nonce + "\n" + 规范化请求体)`。
  所以 Base URL 路径写错只会 404，不会报「验签失败」，排障时别被误导；
- 订单系统对 Timestamp 不做 ±5 分钟过期校验（文档明确重试间隔最长 1 小时且每次重签），
  但插件侧仍会校验 App-ID 与签名。

---

## 阶段五：本地 HTTPS 互信（仅本地开发）

本地两个站点都用 mkcert 自签证书，**系统 curl 走 macOS 钥匙串能通，不代表 PHP 能通**：

| 发起方 | 信任来源 | 默认含 mkcert 根？ |
| --- | --- | --- |
| 系统 curl | macOS 钥匙串 | ✅ |
| WordPress HTTP API | `wp-includes/certificates/ca-bundle.crt` | ❌ |
| PHP / Guzzle | OpenSSL CA 包（brew `ca-certificates`） | ❌ |

因此 WP 调订单系统会报 `cURL error 60: unable to get local issuer certificate (20)`。

**不要**用「关 sslverify」的方式糊过去，本机已采用升级安全的做法：

1. `wp-content/mu-plugins/sscpay-local-ssl.php`
   用 `http_request_args` 过滤器，**仅对** `*.local` / `*.test` / `localhost` / `127.0.0.1` / `::1`
   把 `sslcertificates` 指向 `wp-content/certificates/ca-bundle.crt`（Mozilla 集合 + mkcert 根），
   **不改动 `sslverify`**，生产域名不受影响；
2. 内核 `wp-includes/certificates/ca-bundle.crt` 保持官方原版（升级不会被覆盖，问题不会复发）；
3. PHP 侧信任由 `/opt/homebrew/etc/ca-certificates/cert.pem` 提供（brew 管理）。

体检与一键修复：

```bash
/Users/akilau/sites/.workbuddy/fix-local-ca.sh          # 修复
/Users/akilau/sites/.workbuddy/fix-local-ca.sh --check  # 只体检
```

> ⚠️ `brew upgrade ca-certificates`、重装 PHP、重装 WP 内核后，务必重跑该脚本，
> 否则 Laravel → WP 的 webhook / 查单会以同样的 error 60 中断。

---

## 阶段六：验收清单

| # | 检查项 | 判定标准 |
| --- | --- | --- |
| 1 | 首页可访问 | `https://<域名>/` 200，证书链无告警 |
| 2 | 后台可登录 | `/wp-admin/` 正常，主题/插件页无致命错误 |
| 3 | 购物车/结算页 | 未登录可打开结算页，商品可加入购物车 |
| 4 | 支付方式可见 | 结算页出现「信用卡 / PayPal」等 SSCPay 网关 |
| 5 | 下单 | 插件日志出现 `[响应 200] .../api/order/create`，且带 `order_no` |
| 6 | 跳转 | 浏览器被引导到收银台（`pay_url` 或 `payment_url`） |
| 7 | 回调 | 支付完成后订单变 `paid`，订单备注出现「SSCPay 下单成功」 |
| 8 | 查单兜底 | 「对账」日志有 `[查单] 订单 #N 远端状态：paid` |
| 9 | 幂等 | 重复支付同一订单追加 `-2` 后缀，不产生重复订单 |
| 10 | 日志脱敏 | 插件日志中的 API Key 等敏感字段已脱敏 |
| 11 | 支付方式隔离 | 未指定 `payment_method_key` 时，结算页仍能看到全部可用渠道 |

---

## 阶段七：生产切换清单

1. **域名与证书**：换成公网域名 + Let's Encrypt；`server_name` 同步更新；`home` / `siteurl` 改值。
2. **SSL 互信**：删除 `wp-content/mu-plugins/sscpay-local-ssl.php`（生产域名走公网证书，不需要）。
3. **插件 Base URL**：`live_base_url` 填生产订单系统地址；生产 `API_DOMAIN` 若已配置，host 必须一致。
4. **应用绑定**：把 sscpay-server 后台应用的 `website` 改成新商城域名。
5. **支付方式 domain**：改成新商城域名，否则指定渠道的下单会被 `PaymentMethodDomainMismatchException` 拒单。
6. **域名校验开关**：`OrderCreationService.php:177` 处应用域名校验当前被 `&& false` 关闭（注释「关闭域名验证」），
   属于本地旁路，**上线前需确认是否恢复**。
7. **wp-config**：`WP_DEBUG=false`；`DISALLOW_FILE_EDIT` 打开；盐值重新生成（`wp salt regenerate`）。
8. **权限**：`wp-config.php` 640；Web 根目录无备份与 SQL dump。
9. **定时任务**：确认 `wp cron` 有真实访问触发（禁用系统 crontab 时用 `wp cron event run --due-now` 或系统 cron）。
10. **备份**：数据库定时导出 + `wp-content/uploads` 同步备份。

---

## 附录 A：故障速查

| 现象 | 根因 | 处理 |
| --- | --- | --- |
| `cURL error 60` | CA 包不含 mkcert 根 | 跑 `fix-local-ca.sh`；确认 mu-plugin 在位 |
| 下单 404 | Base URL 带了 `/api`，或指错了站点 | Base URL 只写到域名，别带 `/api` |
| 下单 401 | App-ID / Timestamp / X-Nonce / sign 缺失或算法不一致 | 核对插件与 `ApiAuthentication` 的规范化算法 |
| 下单 500（`$navigationIcon must be BackedEnum\|string\|null`） | Filament 资源属性类型与父类不协变 | 声明成 `string\|\BackedEnum\|null`，别用 `?string` |
| 结算页看不到支付方式 | base_url / app_id / api_key 任一为空 | `is_configured()` 为 false 时网关直接隐藏 |
| 拒单「回跳域名不一致」 | 三个回跳地址 host ≠ `payment_methods.domain` | 对齐 `domain` 字段与商城域名 |
| 支付成功但订单不变 | webhook 未到达 / 验签失败 | 看 `wc-logs/sscpay_creditcard-*.log` 与订单系统日志 |
| 502 | nginx 与 fpm 池不通 | 检查 `fastcgi_pass 127.0.0.1:9000` 与 php-fpm 是否在跑 |

## 附录 B：日志位置速查

| 内容 | 路径 |
| --- | --- |
| SSCPay 支付插件日志 | `wp-content/uploads/wc-logs/sscpay_creditcard-*.log` |
| WooCommerce 其它日志 | `wp-content/uploads/wc-logs/wc_logger-*.log` |
| PHP 错误日志 | `wp-content/debug.log`（需 `WP_DEBUG_LOG`） |
| 订单系统应用日志 | `sscpay-server/storage/logs/laravel.log` |
| 订单系统队列 | `sscpay-server/storage/logs/laravel-queue*.log`（若有） |

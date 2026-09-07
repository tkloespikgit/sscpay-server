# 生产环境部署与运维配置

> 本文档面向**项目上线之后**的服务器运维配置，覆盖运行身份、目录权限、Crontab 定时任务、Supervisor 队列 Worker、日志轮转、Nginx / PHP-FPM 与发布流程。
>
> **核心约定：所有常驻进程与定时任务统一以 `www-data` 用户运行**（Nginx worker、PHP-FPM、Cron、Supervisor 队列 Worker），避免不同身份写文件导致 `storage/` 权限错乱。

## 目录

- [0. 占位符与运行身份](#0-占位符与运行身份)
- [1. 前置软件](#1-前置软件)
- [2. 目录与权限（www-data 属主）](#2-目录与权限www-data-属主)
- [3. 生产环境变量（.env）](#3-生产环境变量env)
- [4. Crontab 定时任务](#4-crontab-定时任务)
- [5. Supervisor 队列 Worker](#5-supervisor-队列-worker)
- [6. 日志轮转（logrotate）](#6-日志轮转logrotate)
- [7. Nginx + PHP-FPM（www-data 运行）](#7-nginx--php-fpmwww-data-运行)
- [8. 首次部署流程](#8-首次部署流程)
- [9. 日常发布（零停机）](#9-日常发布零停机)
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
| Node.js | 18+ | **仅构建期需要**（`npm run build`），可在 CI / 本地构建后只上传 `public/build` |
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
API_DOMAIN=apios.example.com

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
* * * * * cd /var/www/sscpay-server && /usr/bin/php8.2 artisan schedule:finish >> /dev/null 2>&1
```

- `schedule:run`：每分钟触发一次，Laravel 按 `APP_TIMEZONE`（PRC）判断哪些任务到期。
- `schedule:finish`：调度收尾，清理 `withoutOverlapping()` 互斥锁、触发 `->then()` 回调；本系统大量使用 `withoutOverlapping()`，建议保留这一行。
- **必须用 `crontab -u www-data`**，让定时任务以 www-data 身份运行，写入 `storage/` 的文件属主才与 Web / Worker 一致。
- cron 环境 `PATH` 精简（通常 `/usr/bin:/bin`），`db:backup:upload` 内部调用的 `mysqldump` 需在此 `PATH` 中；不在时在命令里写绝对路径或补充 `PATH=`。

> 备选：若不便用 www-data 的 crontab，可放 root crontab 并显式降权：
> `* * * * * cd /var/www/sscpay-server && sudo -u www-data /usr/bin/php8.2 artisan schedule:run >> /dev/null 2>&1`

### 4.2 已配置的调度任务清单

来自 [`routes/console.php`](../routes/console.php)，共 **8 个**（`schedule:run` 每分钟评估一次，实际频率见下表）：

| 命令 | 频率 | 说明 |
|---|---|---|
| `exchange:fetch` | 每小时 | 拉取汇率更新 `exchange_rates`，向 `exchange_rate_histories` 追加快照并按保留期清理 |
| `db:backup:upload` | 每 6 小时 | `mysqldump` 导出 → gzip → 上传阿里云 OSS `backups/` → 清理本地临时文件 |
| `order-events:sync` | 每分钟（`withoutOverlapping`） | 逐笔拉取插件 `/order-logs` 归档订单日志，实际间隔由 `order_event.sync_interval` 动态控制 |
| `order-notifications:process-due` | 每分钟（`withoutOverlapping`） | 扫描到期的失败通知记录并发起下一次尝试 |
| `ad-conversions:process-due` | 每分钟（`withoutOverlapping`） | 扫描到期的广告转化通知重试记录并发起下一次尝试 |
| `order-disputes:close-due` | 每 5 分钟（`withoutOverlapping`） | 自动结束已到期的争议事件（释放冻结资金） |
| `order-disputes:send-reminders` | 每 5 分钟（`withoutOverlapping`） | 24 小时内到期的争议事件发 Telegram 提醒 |
| `fund-freezes:release-due` | 每 5 分钟（`withoutOverlapping`） | 扫描到期的资金冻结记录并自动释放 |

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
npm install && npm run build           # 或在 CI 构建后仅上传 public/build

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
/usr/bin/php8.2 artisan config:cache
/usr/bin/php8.2 artisan route:cache
/usr/bin/php8.2 artisan view:cache
/usr/bin/php8.2 artisan filament:upgrade
/usr/bin/php8.2 artisan storage:link    # 如使用 public 磁盘

# 7. 权限归位（部署过程可能产生非 www-data 文件）
sudo chown -R www-data:www-data /var/www/sscpay-server
sudo chmod -R ug+rwx /var/www/sscpay-server/storage /var/www/sscpay-server/bootstrap/cache

# 8. 拉起 Worker + 配置 cron（第 4、5 节）
sudo supervisorctl reread && sudo supervisorctl update
sudo crontab -u www-data -e
```

---

## 9. 日常发布（零停机）

```bash
cd /var/www/sscpay-server
sudo -u www-data git pull                # 或 rsync 部署产物

composer install --no-dev --optimize-autoloader
npm run build                            # 前端有变更时

/usr/bin/php8.2 artisan migrate --force
/usr/bin/php8.2 artisan config:cache && /usr/bin/php8.2 artisan route:cache && /usr/bin/php8.2 artisan view:cache
/usr/bin/php8.2 artisan filament:upgrade

# ⚠️ 关键：改了任何 Job / 队列 / 监听器代码后，必须通知 Worker 重启，
# 否则常驻进程仍跑旧代码。queue:restart 写时间戳到 Redis cache，
# Worker 处理完当前 Job 后自行退出，再由 Supervisor autorestart 拉起新进程。
/usr/bin/php8.2 artisan queue:restart
```

- `queue:restart` 对**所有** Worker 生效，依赖 Redis 连通性；若 Redis 认证失败（如 `WRONGPASS`）则不生效，此时改用 `sudo supervisorctl restart sscpay-server-worker-fast:* sscpay-server-worker-slow:*`。
- `--max-time` 是兜底：即使漏跑 `queue:restart`，Worker 也会在运行满设定秒数后自动重启加载新代码。

---

## 10. 上线检查清单

- [ ] `.env`：`APP_ENV=production`、`APP_DEBUG=false`、`APP_KEY` 已生成且与生产一致
- [ ] `QUEUE_CONNECTION=redis` / `CACHE_STORE=redis` / `SESSION_DRIVER=redis`
- [ ] **`REDIS_QUEUE_RETRY_AFTER=1900`**（> 慢车道 `--timeout=1800`）
- [ ] `storage/`、`bootstrap/cache/`、`storage/app/backups/` 属主为 `www-data` 且可写
- [ ] `crontab -u www-data -l` 含 `schedule:run`（+ `schedule:finish`）
- [ ] `sudo -u www-data php artisan schedule:list` 列出全部 8 个调度任务
- [ ] `sudo supervisorctl status` 两池均 `RUNNING`，且 `user=www-data`
- [ ] `mysqldump` 在 cron 的 `PATH` 内，`db:backup:upload` 手动跑一次能上传到 OSS
- [ ] `/etc/logrotate.d/sscpay-server` 已配置（`copytruncate` + `su www-data www-data`）
- [ ] Nginx `root` 指向 `public/`，`.env` / `.git` 被 deny
- [ ] `php artisan config:cache && route:cache && view:cache && filament:upgrade` 已执行
- [ ] 健康检查 `GET /up` 返回 200
- [ ] 后台 `/admin` 能用超级管理员登录；`GET /sync/products/{paymentMethod}` 调试入口已按 README「12.10」移除或加权限中间件
- [ ] `failed_jobs` 表存在，监控失败任务：`php artisan queue:failed`

---

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

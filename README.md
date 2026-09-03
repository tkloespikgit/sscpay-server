# 多商户订单系统 — 生成代码说明

本包是这次对话里逐步生成的全部代码，按 Laravel 项目的标准目录结构组织，
解压后可以直接把各个目录合并到一个新建的 `laravel new` 项目里。

## 目录结构

```
app/
  Console/Commands/     后台命令（汇率拉取、DB备份、事件同步、通知重试扫描、角色补建）
  Events/                OrderStatusChanged / LogisticsImportCompleted / OrderEventsSyncCompleted
  Exceptions/            AmountMismatchException 等业务异常
  Filament/               后台 UI（Resources / Pages / Widgets）
  Http/
    Controllers/          OrderController（API）、PaymentPageController（付款落地页）
    Middleware/           ApiAuthentication（App-ID+签名鉴权）
    Requests/Api/         CreateOrderRequest
  Jobs/                   队列任务（付款链接邮件、通知重试、物流导入）
  Listeners/              SendTelegramNotification
  Mail/                   PaymentLinkMail
  Models/                 全部 Eloquent 模型 + MerchantScope 多租户隔离
  Observers/               OrderShippingObserver（发货状态自动机）、MerchantObserver（自动建角色）
  Providers/               AppServiceProvider、EventServiceProvider
  Services/               核心业务逻辑（下单、支付风控、通知、仪表盘统计等）
  Support/                Permissions（权限常量）
database/
  migrations/             18 个迁移文件（含 2026_07_05_000016 缺号是正常的，见下方说明）
  seeders/                DatabaseSeeder / SystemConfigSeeder / PermissionSeeder
resources/views/          Filament 自定义页面视图 + 付款页面视图
routes/
  api.php                  对外下单/查询接口路由（可直接使用）
  web-payment-snippet.php  付款页面路由片段（需手动合并进 routes/web.php）
  console-schedule-snippet.php  定时任务片段（需手动合并进 routes/console.php）
bootstrap/
  app-snippet.php          中间件别名注册片段（需手动合并进 bootstrap/app.php）
```

> **migrations 编号说明**：`2026_07_05_000016` 这个编号被跳过了——早期版本里
> 曾经规划一张表用这个序号，后来设计调整后作废，没有重新压号，纯粹是编号
> 不连续，不影响迁移执行顺序（Laravel 按文件名排序执行，不看数字是否连续）。

## 多语言支持

后台默认语言是**英文**，中文语言包已经翻译好一起提供（`lang/en/admin.php`
基准 + `lang/zh_CN/admin.php` 翻译）。要加别的语言，复制 `lang/en/admin.php`
翻译一下、改个 `.env` 配置就行，不用碰任何 PHP 代码。完整说明、以及三处
没有覆盖到的地方（Filament 自带 UI 文案、数据库里的配置说明文字、
按商户各自语言发通知）见 `docs/i18n.md`。

## 安装步骤

### 0. 本地用 Docker 跑（可选，更省事）

如果不想手动装 PHP/MySQL/Redis，直接用根目录的 `docker-compose.yml` +
`docker/` 文件夹，一条 `docker compose up -d --build` 就能把 app、nginx、
MySQL、Redis、队列消费者、定时任务调度器、Mailpit（假邮件服务器）全部跑起来。
详细步骤和各服务地址见 `docker/README.md`。跳过下面 1~4 步，直接看该文件即可。

### 1. 新建 Laravel 12 项目并安装依赖包

```bash
composer require filament/filament:"^5.0"
composer require spatie/laravel-permission
composer require league/flysystem-aws-s3-v3  # 或阿里云 OSS 专用的 Flysystem adapter
```

把本包的 `app/`、`database/`、`resources/` 目录内容合并（覆盖式复制）到你的项目对应目录。

### 2. 手动合并的三个片段文件

这三个文件**故意没有**直接叫 `bootstrap/app.php` / `routes/web.php` / `routes/console.php`，
是为了不覆盖你项目里已有的这几个文件。需要手动把内容合并进去：

- `bootstrap/app-snippet.php` → 合并进 `bootstrap/app.php` 的 `->withMiddleware()` 部分
- `routes/web-payment-snippet.php` → 合并进 `routes/web.php`
- `routes/console-schedule-snippet.php` → 合并进 `routes/console.php`

`routes/api.php` 本身是完整文件，可以直接使用（如果你已有 `routes/api.php`，
把里面的 `Route::middleware(['api.auth'])->...` 这段合并进去即可）。

### 3. 配置

- `config/filesystems.php` 加一个 `oss` disk（需求文档 7.4 节已给出配置示例，
  这次生成的代码里没有重复给出该文件，因为它属于纯配置、不含业务逻辑）。
- `config/services.php` 加：
  ```php
  'exchange_rate' => [
      'url' => env('EXCHANGE_RATE_API_URL'),
      'key' => env('EXCHANGE_RATE_API_KEY'),
  ],
  ```
- Filament Panel 配置（MFA 部分）参考需求文档 4.10 节，本次没有重新生成
  `AdminPanelProvider.php`，因为文档里已经给出了完整代码。
- `.env` 加 `APP_LOCALE=en`（默认英文，见下方"多语言支持"一节，
  `config/app.php` 对应改动见 `config/app-locale-snippet.php`）。

### 4. 迁移 + Seeder

```bash
php artisan migrate
php artisan db:seed --class=DatabaseSeeder
```

`DatabaseSeeder` 会依次跑 `SystemConfigSeeder` 和 `PermissionSeeder`。

### 5. 给已有商户补建默认角色（如果是老数据库）

`MerchantObserver` 只对**新建**商户自动生效。如果数据库里已经有商户，需要手动跑一次：

```bash
php artisan merchants:provision-roles
```

该命令幂等，可以放心重复执行。

### 6. 创建平台超级管理员

```bash
php artisan make:super-admin
```

交互式输入姓名/邮箱/密码即可。**不要用 Filament 自带的 `make:filament-user`**
——那个命令不知道 `is_super_admin` 这个字段，建出来的账号登录后台后
什么功能都看不到（所有 Resource 的权限判断都依赖这个字段或者
`Gate::before` 短路逻辑）。

---

## ⚠️ 尚未完成 / 需要你决定的缺口（务必逐条确认）

这些是整个对话过程里明确提醒过、但受限于需求文档没有给出足够信息、
因此没有（也不应该由我替你们）编造实现的地方：

1. **支付网关对接（`PaymentPageController::confirm()`）**
   文档原话是"调用现有支付确认接口"，暗示这部分已有实现或不在本次需求范围内。
   当前只是一个骨架方法，**订单从 `pending` 变成 `paid` 这个关键状态转换目前完全没有代码路径**。
   需要在实际网关回调成功的地方补上：
   ```php
   $order->update(['status' => 'paid']);
   event(new \App\Events\OrderStatusChanged($order, 'pending', 'paid'));
   app(\App\Services\OrderNotificationService::class)->dispatchInitial($order);
   ```

2. **API 签名算法变更需要同步给商户**
   `sign` 字段改成放在请求体里之后，签名计算用的是"剔除 sign 字段 + 递归按
   key 排序 + 重新编码"的规范化算法（见 `ApiAuthentication::canonicalize()`），
   商户端签名代码必须用同一套算法，否则永远验签失败。这是双方协议的破坏性变更，
   如果已经有商户在对接测试，务必提前同步。

3. **汇率数据提供商未定**
   `FetchExchangeRates` 命令里 `fetchRatesFromProvider()` 是占位实现，
   `config('services.exchange_rate.url')` 不配置的话这个命令什么都不会做。
   需要接入你们实际选定的汇率 API。

4. **`OrderEventSyncService` 的 `merchant_order_no` 消歧风险**
   外部系统按文档只返回 `merchant_order_no`，但这个字段只在同一商户下唯一，
   不同商户可能撞出相同值。当前用"唯一匹配才写入，查到多条且无法用 app_id
   消歧就跳过并记警告日志"的保守策略处理。建议尽快确认外部系统能否在
   payload 里带上足以定位商户的字段（如 `app_id`）。

5. **Filament 5 API 细节需要对照实际安装版本核对**
   Filament 5.0 是很新的版本，生成代码时用的是 Filament 4+ 引入的
   `Schema`/`Schemas\Components` 写法，但具体类名、方法签名可能和你们
   实际安装的版本有出入，建议对照官方文档跑一遍再上线。

6. **权限粒度目前只做到 Resource 级别**
   `Permissions` 类定义的是"能不能进这个功能模块"，还没有做更细的行级权限
   （比如"订单管理员只能看自己创建的订单"这种）。如果需要，要在各 Resource
   的 `getEloquentQuery()` 里再加一层过滤。

7. **`config/filesystems.php` 的 oss 磁盘配置、`AdminPanelProvider.php` 的
   MFA 配置**没有重新生成文件，因为需求文档原文里已经给出了完整代码
   （7.4 节、4.10 节），直接照抄即可。

---

生成过程中所有的设计取舍、以及每一步为什么这么做，在对话历史里都有详细
说明，如果后续维护时对某处代码的意图有疑问，可以回头翻聊天记录里对应的
解释。

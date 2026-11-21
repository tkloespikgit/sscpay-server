# 数据库迁移 + 在线更新：使用说明

这份文档配合 `src/Core/Migrator.php`、`src/Core/UpdateChecker.php`、
`sscpay-gateway-aggregator.php`、`.github/workflows/release.yml` 一起看。

## 1.15.0 商品同步接口与回调密钥

- 更新聚合插件后，停用独立的 `sscpay-products-api`。使用原 WooCommerce REST API 密钥检查 `/wp-json/wc/v3/products-with-variations` 和 `/wp-json/wc/v3/ninox-status` 后移除旧插件。
- 出站回调签名密钥可在「支付网关聚合 → 设置」直接修改。保存值优先，尚未保存时可通过站点配置 `PGA_DEFAULT_OUTBOUND_SHARED_SECRET` 预填。Laravel 的 `PGA_WEBHOOK_SECRET` 使用相同的值。
- 本次无需数据库结构迁移，`PGA_DB_VERSION` 仍为 `1.6.0`。

## 1.13.7：PayPal 付款来源拒绝后继续支付

- `INSTRUMENT_DECLINED` 单独识别：JS 按钮通过 `actions.restart()` 引导买家换付款来源；
  REST 跳转流程使用经过 PayPal 域名校验的继续付款地址，不再仅落到等待结果页。
- 重新打开原付款链接时先查询 PayPal。无 Capture / Authorization 的已批准或待买家操作 Order
  继续使用原 Order，即使超过本站两小时会话期限也不创建第二个可扣款订单。
- 历史拒付且没有恢复标记的订单，也可用原会话缓存恢复；本站链接已关闭或过期时不会重开。
- 已收款、扣款处理中、未知状态、查询错误及并发会话变更维持原有校验和保护。

本次无需数据库结构迁移，`PGA_DB_VERSION` 仍为 `1.6.0`。恢复信息保存在已有会话 JSON 中。
验证覆盖渠道错误、前端恢复脚本、重新打开链接、期限、历史缓存和并发保护；
尚未使用真实 PayPal 沙箱账号验证弹层交互与换付款来源后的 capture。

## 1.13.6：SOCKS5 出站代理

- 普通渠道 API 请求和 Stripe SDK 共用代理协议解析，支持 `http://`、`socks5://`、`socks5h://`。
- `socks5://` 使用本站服务器解析目标域名，`socks5h://` 由代理解析；两者均支持用户名和密码认证。
- 原有 HTTP 代理配置继续可用；账号和密码中的特殊字符须分别进行 URL 编码。
- 显式配置代理后，非法地址、不支持的协议和连接失败不会回落为直连；错误信息不带代理凭据。
- 代理请求不再被服务器的 `NO_PROXY` 环境变量绕过，Stripe 切换配置时会重置代理选项。

配置示例：`"proxy": "socks5://account:password@proxy.example.com:1080"`。
请将主机和端口替换为本站服务器可访问的真实代理地址，不要使用监听地址 `0.0.0.0`。
历史订单仍使用创建时保存的配置快照，更新配置不会改写历史快照。

本次无需数据库结构迁移，`PGA_DB_VERSION` 仍为 `1.6.0`。
新增本地代理回归测试，覆盖三种协议在普通客户端和 Stripe 客户端的真实认证握手；
不连接真实支付平台，生产环境仍需使用自己的代理和支付账户验证连通性。

## 1.13.5：后台日志分页

- 日志列表每页显示 50 条，表格上下提供首页、上一页、下一页、末页及页码跳转。
- 翻页保留订单号和日志级别筛选，新检索从第一页开始。
- 订单号与日志级别可同时过滤，订单日志不再受后台原有 500 条展示上限影响。
- 越界页码自动定位到有效页；空结果、单页结果不显示无效的翻页控件。

本次无需数据库结构迁移，`PGA_DB_VERSION` 仍为 `1.6.0`；订单日志 API 的单次
500 条上限保持原有行为。验证包含 272 项回归及分页链接的渲染检查。

## 1.13.4：Stripe Webhook 修复

本次只更新代码，数据库结构版本仍为 `1.6.0`。上传完整插件 ZIP 覆盖安装，保持原有
Webhook URL、签名密钥和加密配置。无需重新创建 Stripe endpoint。

- 无关折扣、Mandate、PI-created 事件直接 200 忽略，避免 `di_`、`mandate_` 被误当订单引用。
- 新支付尝试将随机关联键同时写入 Session 与 PaymentIntent metadata；旧创建草稿维持
  原参数版本，避免跨版本重试时触发 Stripe 幂等键参数冲突。
- 旧订单 PI 尚未落库时，先验证签名，再通过 Stripe 的 Session/PI 关联查询找订单。
  已注册配置与历史快照都可作为 API 凭据候选，支持 API key 轮换；最终仍用订单快照验签。
- 补齐 Session 异步成功/失败；`completed(unpaid)` 仅保存关联，不提前标记付款。
- 部分退款保留付款状态并记录金额；争议处理在订单锁内查询当前状态，避免旧通知重开争议。
  退款/争议先于付款到达时返回 503，待付款确认后重投处理。
- Stripe 去重、订单状态、Woo 数据库更新和 S 系统回调入队放在同一事务。
  普通 SQL 失败、Woo 返回 false 或入队失败会回滚并返回 500，不吞成 200。
  会检查所需支付表及存在的相关 Woo 表使用 InnoDB；不支持事务时拒绝消费事件并记录原因，
  不自动转换现有数据库表。
- S 系统通知在事务提交后异步唤醒 WP-Cron，避免 webhook 等待 S 系统网络响应。
  常规队列继续兜底；站点原有的系统 cron 应保持运行。队列支持并发认领、异常重试和中断恢复。

Stripe endpoint 应使用 Snapshot 格式；完整订阅列表见 README 的网关后台配置一节。
部署后可在 Stripe Dashboard 对原先 `Order not found` 的资金事件点击 Resend，确认订单状态
和 S 系统通知都已更新。折扣、Mandate 等无关事件重发后应收到 200。
旧版本已错误确认消费的事件，不一定能靠 Resend 补齐全部副作用；应通过查单核实并核对 Woo
订单及 S 系统回调记录，不要批量删除去重记录或手动重新开放已付款订单。

验证使用隔离数据库与 Stripe API 边界，覆盖签名、关联、回滚、密钥轮换、乱序和队列恢复；
尚未在真实 Stripe 账户或真实数据库断线条件下验证。事务不能撤销 Woo 邮件及第三方钩子已
执行的外部动作，wpdb 自动重连也可能打断事务边界，因此本次不承诺所有断线情形下严格
只执行一次；S 系统仍须按订单与状态做好幂等处理。

## 一、以后要加新的SQL，具体怎么操作

1. 打开 `src/Core/Migrator.php`，在 `migrations()` 数组末尾新增一行：

   ```php
   '1.1.0' => [self::class, 'migrate_1_1_0'],
   ```

2. 在类里写一个对应的 `private static function migrate_1_1_0($wpdb) { ... }`。
   - **建表/加字段/加索引**：用 `dbDelta()`，传入这张表**完整**的目标结构（不是只写新增的那一行），
     dbDelta 会自动比对当前结构、只执行需要的变更，已有数据不受影响，可以放心重复执行。
   - **数据回填**（比如给新字段设默认值、拆分/合并已有字段）：用普通的
     `$wpdb->query(...)`，写在 `dbDelta()` 调用之后，并且要写成幂等的
     （反复跑不会出错，因为不同站点升级的时间点不一样，理论上可能被执行多次）。
   - 参考 `migrate_1_0_0()` 的写法（建表）和文件末尾 `migrate_1_1_0_EXAMPLE()` 的写法（加字段+回填，仅作模板，没有被实际注册执行）。

3. 打开主插件文件 `sscpay-gateway-aggregator.php`，把 `PGA_DB_VERSION` 常量
   改成 `1.1.0`（和第1步的版本号一致）。同时把上面的 `Version:` 头也提升
   （建议数据库版本和插件包版本保持同步用同一个号，减少心智负担）。

4. **重要**：已经发布出去的迁移版本（比如已经在用的 `1.0.0`），发布之后就不要
   再改内容了——不同站点是在不同时间点升级的，如果事后偷偷改了 `migrate_1_0_0`
   的内容，先升级的站点和后升级的站点会得到不一样的表结构。真要修正，
   开一个新版本号（比如 `1.0.1`）去修正，而不是改历史版本。

5. 本地或预发环境验证：装一份"升级前"的旧版本插件，覆盖成新代码后触发一次
   页面请求（`plugins_loaded` 钩子会自动跑 `Migrator::maybeUpgrade()`），
   确认新表/新字段按预期出现，且失败时后台会弹出错误提示（对应
   `admin_notices` 那段代码），而不是静默失败。

6. 打tag发布（见下面第二节），线上站点更新完插件包之后，下一次有人访问网站时
   `plugins_loaded` 会自动检测到 `PGA_DB_VERSION` 比数据库里存的旧，
   自动跑完 `1.0.0` 之后、`1.1.0` 之前所有还没跑过的迁移。**这一步不需要人工登录后台点什么，是自动的。**

## 二、在线更新怎么配置（GitHub方案）

选择的是 GitHub私有/公开仓库 + [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) 库，
而不是自己实现更新检测——这块涉及版本比较、缓存、私有仓库鉴权，用被广泛验证过的库更稳妥，
尤其这是一个支付插件，更新机制出问题的代价比较高。

### 首次配置

1. 把插件代码推到一个GitHub仓库（比如 `your-org/sscpay-gateway-aggregator`）。
2. `composer require yahnis-elsts/plugin-update-checker`，确认 `vendor/` 目录生成。
3. 修改 `src/Core/UpdateChecker.php` 里的默认仓库地址，或者在站点的
   `wp-config.php` 里加一行覆盖（不改代码也能配置）：

   ```php
   define('PGA_UPDATE_REPO_URL', 'https://github.com/your-org/sscpay-gateway-aggregator/');
   ```

4. 如果仓库是**私有**的，还需要一个有 `repo` 读权限的 GitHub Personal Access Token
   （建议用 fine-grained token，只给这一个仓库的只读权限），同样放进 `wp-config.php`：

   ```php
   define('PGA_GITHUB_TOKEN', 'github_pat_xxxxxxxxxxxx');
   ```

   **不要**把token写进代码仓库或数据库——`wp-config.php` 通常不在版本控制里，是相对安全的位置。

5. 在GitHub仓库的 Settings → Secrets 确认 `GITHUB_ACTIONS` 有默认的 `GITHUB_TOKEN`
   权限（一般不用额外配置，Actions自带）。

### 和其它 sscpay-* 插件共用同一个GitHub账号时

如果 `sscpay-gateway-aggregator`、`sscpay-payment-paypal` 和 `sscpay-payment-creditcard`
用的是同一个 GitHub 账号，可以在 `wp-config.php` 里定义下面这对共用常量。各插件按自己的目录名推导仓库名。

```php
define('SSCPAY_GITHUB_OWNER', 'your-org');
define('SSCPAY_GITHUB_TOKEN', 'github_pat_xxxxxxxxxxxx');
```

插件专属常量（如上面第3、4步的 `PGA_UPDATE_REPO_URL` / `PGA_GITHUB_TOKEN`）优先级更高，
只在需要给某个插件单独覆盖时才需要定义。

### 每次发新版本

1. 改代码，按第一节的方式处理数据库变更（如果有的话）。
2. 把主插件文件里的 `Version:` 头和 `PGA_VERSION` 常量改成新版本号，比如 `1.1.0`。
3. `git commit`、`git tag v1.1.0`、`git push --tags`。
4. GitHub Actions（`.github/workflows/release.yml`）会自动：
   跑 `composer install --no-dev` → 排除 `.git`/测试文件等 → 打包成
   `sscpay-gateway-aggregator.zip` → 作为这个tag的 Release 资源包上传。
   **不要**依赖GitHub自动生成的"源码压缩包"，那个包里没打包composer依赖，装上会直接报错——
   这也是为什么 `UpdateChecker.php` 里显式调用了 `enableReleaseAssets()`，
   要求只信任手动/CI上传的资源包。
5. 线上站点的WP后台"插件"页面，12小时内（或管理员手动刷新一次插件列表页）
   会看到"有新版本可更新"的提示，点更新，WP会下载这个zip资源包并覆盖安装，
   完成后下一次请求会自动触发数据库迁移检查（见第一节第6点）。

### 版本号对应关系

| 位置 | 作用 |
|------|------|
| 主插件文件 `Version:` header | WP判断"线上是否有新版本"的依据，UpdateChecker比较的就是这个值 |
| GitHub Release tag（如 `v1.1.0`） | 必须和上面的版本号对应，PUC默认会自动去掉tag开头的`v`再比较 |
| `PGA_DB_VERSION` 常量 | 数据库结构版本，只有涉及建表/加字段才需要提升，和插件包版本是两件独立的事，但建议保持同步 |

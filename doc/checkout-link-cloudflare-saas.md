# 收款链接自定义域名：Cloudflare for SaaS 对接

> 本文面向**运维**。目标：让商户用自己的域名（如 `checkout.tvbox.com`）承载收款链接落地页，并自动获得有效的 HTTPS 证书。
>
> **核心结论：源站服务器基本不用改。** 现有的 Nginx 配置、证书、certbot 全部保持原样，只需要在 `server_name` 里加一个主机名。

---

## 1. 为什么选这个方案

商户要用自己的域名，而 HTTPS 要求每个域名都有对应证书。原版 Nginx 的 `ssl_certificate` 必须在配置文件里写死路径，没有"握手时现场签发"的能力——意味着**每接一个商户都要改生产配置 + 跑一次 certbot**，不可扩展。

两个可选解法：

| 方案 | 对现有服务的影响 |
|---|---|
| Caddy on-demand TLS | Caddy 必须独占 443，Nginx 要挪到 8080。一次全站拓扑变更，爆炸半径包含商户 API |
| **Cloudflare for SaaS（本方案）** | **源站零拓扑变更**，风险完全隔离在新功能内 |

这是一个正在跑的支付系统，商户 API 断了就是真金白银的损失，所以选了后者。代价是按自定义域名数量计费，以及多一层 Cloudflare 依赖。

流量路径：

```
客户 ──> Cloudflare 边缘（用 CF 签发的证书终止 TLS）
          └──> link.yourpay.com（你现有的 Nginx + 现有证书）
```

---

## 2. 一次性准备（运维做，约 20 分钟）

### 2.1 前提

- 你有一个托管在 Cloudflare 上的域名（下文记作 `yourpay.com`），且该 zone 已开通 Cloudflare for SaaS。
- 这是**付费能力**，具体价格以 Cloudflare 当前定价为准，按自定义主机名数量计费。

### 2.2 准备回退源站（Fallback Origin）

选一个你控制的主机名作为所有商户域名的回源目标，例如 `link.yourpay.com`。

1. 在 Cloudflare DNS 里给 `link.yourpay.com` 加一条 A 记录指向源站 IP，**代理状态设为「已代理」（橙云）**。
2. 在 Cloudflare 控制台 → SSL/TLS → Custom Hostnames → 设置 Fallback Origin 为 `link.yourpay.com`，等待状态变为 Active。

### 2.3 源站 Nginx（唯一的服务器改动）

把回退源站主机名加进现有的 `server_name`：

```nginx
server {
    listen 443 ssl;
    server_name pay.example.com sma.example.com applo.example.com apios.example.com
                link.yourpay.com;          # ← 只加这一行里的这一个名字
    # 其余配置、证书路径全部不动
}
```

源站证书需要覆盖 `link.yourpay.com`。跑一次 `certbot --nginx -d link.yourpay.com --expand` 即可，或者把 Cloudflare 的 SSL 模式设为 Full（非 Strict）绕开这一步——**生产环境建议老老实实签证书并用 Full (Strict)**，Full 模式不校验源站证书，中间人可以冒充源站。

改完 `nginx -t && nginx -s reload`。**此时现有服务没有任何行为变化**，只是多认了一个主机名。

### 2.4 创建 API Token

Cloudflare 控制台 → My Profile → API Tokens → Create Token，权限至少需要：

| 类型 | 资源 | 权限 |
|---|---|---|
| Zone | 你的 zone（yourpay.com） | SSL and Certificates : Edit |

**不要用 Global API Key。** 那个 key 权限是账号级全通的，一旦泄露对方能改你所有域名的 DNS。

### 2.5 配置环境变量

```env
CLOUDFLARE_API_TOKEN=你刚创建的 token
CLOUDFLARE_ZONE_ID=在 Cloudflare 控制台域名概览页右下角
CLOUDFLARE_FALLBACK_ORIGIN=link.yourpay.com
```

改完执行 `php artisan config:clear`。

### 2.6 确认可信代理配置已生效

流量经过 Cloudflare 之后，源站看到的 `REMOTE_ADDR` 是 CF 边缘节点的 IP。代码里已经通过 `App\Support\CloudflareIpRanges` 把 CF 网段配成了可信代理（见 `bootstrap/app.php`），不用额外配置，但要知道它存在——**没有它的话 `orders.customer_ip` 会全部变成 CF 的 IP，落地页的同 IP 频率限制也会整个失效。**

收款页按访客 IP 推荐国家还需要在 Cloudflare 控制台开启 **Rules → Transform Rules → Managed Transforms → Add visitor location headers**（或开启 IP Geolocation），让源站收到 `CF-IPCountry`。应用只在请求确实来自 Cloudflare 网段且该国家属于链接支持范围时使用此头；本地直连访问不会按 IP 推荐。

⚠️ 这个列表里**只有 CF 的网段，绝不能改成 `*`**。商户 API（`/api/order/create`）和后台面板是直连源站的、不走 CF；配成 `*` 等于允许任何人伪造 `X-Forwarded-For` 冒充任意来源 IP，把下单接口的 IP 风控整个绕过去。

CF 偶尔会增删网段。已挂了一个每周一早 9 点的调度任务自动核对：

```bash
php artisan checkout:check-cloudflare-ips
```

有差异时命令返回非 0 退出码，按提示更新 `app/Support/CloudflareIpRanges.php` 即可。**建议把这个任务的失败接进你们的告警**——CF 加了新网段而我们没跟上是静默失败，不会报错，只会让部分客户的 IP 悄悄失真。

---

## 3. 每接一个商户（商户自助，运维不参与）

商户在后台「支付设置 → 自有域名」里完成，流程是两步 DNS + 一次点击：

1. **录入域名**，如 `checkout.tvbox.com`。系统给出一条 TXT 记录。
2. **加 TXT 记录**（证明域名归属），然后点「验证」。
3. 系统验证归属通过后自动调 CF API 注册自定义主机名，CF 返回一条 **DCV 校验记录**，商户同样加到 DNS。
4. **加 CNAME 记录**：`checkout.tvbox.com` → `link.yourpay.com`。
5. 等 CF 签发证书。证书状态由 `checkout:sync-domains`（每 15 分钟）自动轮询刷新，商户也可以手动再点一次「验证」立即刷新。

证书变成 `active` 之后，这个域名才会出现在「创建收款链接」的域名下拉里。

### ⚠️ 必须告诉商户的两件事

**1. 用子域名，不要用根域名。** 根域名不能加 CNAME 记录（DNS 协议限制，除非 DNS 商支持 ALIAS/ANAME），而本方案的接入方式正是 CNAME。后台表单已经强制拦了根域名。

**2. CNAME 记录必须是「仅 DNS」（灰云），不能开代理（橙云）。** 如果商户自己的域名也托管在 Cloudflare 并且开了橙云代理，TLS 会在商户自己的 CF 账号下终止，DCV 校验流量到不了我们这边，证书永远签不下来。这是预计最高频的客诉，后台提示文案里已经写了。

---

## 4. 排查

| 现象 | 原因与处理 |
|---|---|
| 点「验证」提示 TXT 记录找不到 | DNS 尚未传播，等几分钟重试。也可能商户把记录加到了根域名而不是 `_sscpay-challenge.` 子名下 |
| 归属验证通过，证书一直 pending | 商户没加 CF 返回的 DCV 记录，或者域名开了橙云代理 |
| 域名打开显示证书错误 | 证书还没到 active；或者商户 CNAME 指错了目标 |
| 落地页 404 | 该域名未验证/未启用，或者用这个域名去访问了不属于它的链接（`CheckoutLink::allowedHosts()` 的防钓鱼校验） |
| 订单里的 `customer_ip` 都是 CF 的 IP | `CloudflareIpRanges` 列表过期，跑 `php artisan checkout:check-cloudflare-ips` |

查某个域名当前状态：

```bash
php artisan checkout:sync-domains
```

---

## 5. 相关命令

| 命令 | 调度 | 用途 |
|---|---|---|
| `checkout:sync-domains` | 每 15 分钟 | 刷新 CF 侧证书签发状态 |
| `checkout:check-cloudflare-ips` | 每周一 09:00 | 核对可信代理网段是否与 CF 官方一致 |
| `permissions:rollout-checkout-links` | 按需手动运行 | 按当前角色模板为存量「商户管理员」「订单管理员」补发收款链接和域名权限；也可使用超管后台“角色权限分配”保存并同步 |

---

## 6. 部署检查清单

- [ ] `link.yourpay.com` 已加进 Nginx `server_name`，且源站证书覆盖它
- [ ] Cloudflare Fallback Origin 已设置并为 Active
- [ ] `CLOUDFLARE_API_TOKEN` / `CLOUDFLARE_ZONE_ID` / `CLOUDFLARE_FALLBACK_ORIGIN` 已配置
- [ ] `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` 已配置（**不配等于没开人机验证**，见下方警告）
- [ ] 需要 Google 地址建议的商户在收款链接中填写自己的 Google Maps 浏览器 Key，并按 HTTP referrer 限制可用域名；留空使用国家、省州、城市联动选择
- [ ] `CHECKOUT_MEDIA_DISK` 指向公开可读的盘（生产建议 `oss`，对应目录设为公共读）
- [ ] `php artisan migrate --force` 已执行
- [ ] `php artisan permissions:rollout-checkout-links` 已执行
- [ ] 系统配置 `order.platforms` 里加上了 `checkout_link`

> ⚠️ `order.platforms` 请在**后台「系统配置」页面手工加**这一个值，**不要**重跑 `SystemConfigSeeder`。那个 seeder 用的是 `updateOrInsert`，重跑会把 `exchange.supported_currencies`、汇损百分比等一整批配置全部重置回默认值，覆盖掉你们线上调过的参数。
>
> 漏了这一步不影响下单（`Order::PLATFORMS_FALLBACK` 里有兜底值），只是后台订单列表的「平台」筛选下拉里看不到这个选项。
- [ ] `npm run build` 已执行（落地页样式走 Vite）
- [ ] 调度任务已生效（`php artisan schedule:list` 能看到两条 checkout 相关任务）

> ⚠️ **Turnstile 未配置时，`TurnstileService::verify()` 直接返回 true**（为了本地开发不强制接入外部服务）。这意味着生产环境忘配密钥不会报任何错，但人机验证等于完全没开，落地页会被脚本刷单。上线前务必确认这两个环境变量已填。

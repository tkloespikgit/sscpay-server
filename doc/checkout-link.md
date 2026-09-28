# 收款链接（Checkout Link）

商户在后台创建一条可反复使用的收银链接，挂在广告、邮件或社交媒体上。客户打开后填写联系方式与收货地址，系统当场建单并把他送去支付网关收银台。

---

## ⚠️ 先分清两个"付款链接"

系统里有两个名字相近、完全不同的东西：

| | `orders.payment_link_token` | **收款链接**（本文） |
|---|---|---|
| 归属 | 挂在**订单**上 | 独立对象，**先有链接后有订单** |
| 产生方式 | 下单成功后系统生成，邮件发给客户 | 商户在后台手动创建 |
| 复用 | 一次性，绑死一笔订单 | 可被任意多个客户反复打开 |
| 页面 | `/payment/{token}` | `/c/{slug}` |
| 代码 | `PaymentPageController`、`SendPaymentLinkJob` | `CheckoutLinkController`、`CheckoutLinkOrderService` |

代码里一律用 `checkout_link` 命名，不要写成 `payment_link`。

---

## 一、创建一条收款链接

后台「支付设置 → 收款链接」。

### 基本信息

| 字段 | 说明 |
|---|---|
| 应用 | 决定订单归属哪个应用，以及用它的邮件/广告平台凭证 |
| 支付组 | 决定这笔钱走哪条通道。下单时系统在组内按权重与风控自动锁定一个支付方式 |
| 标题 | 显示在收款页顶部，如 `TV box - model 209343 checkout` |
| Logo | 显示在收款页顶部 |
| 自有域名 | 可选。只列出已验证且证书生效的域名，留空则用平台默认域名 |

> 应用和支付组都直属商户、彼此没有关联，是两个独立的选择，不存在级联过滤。

### 金额与币种

- **币种是单值**，客户不能在页面上切换。一条链接只收一种币种。
- **金额模式**二选一：
  - `固定金额`：金额写死，客户改不了。
  - `金额区间`：客户在 `[下限, 上限]` 内自己输入。

### 商品明细（可选）

可以配一批商品（名称/图片/单价/数量）+ 运费、税费、折扣，会以小票形式显示在收款页上。

> **一旦配置了商品、运费、税费或折扣中的任意一项，金额模式就必须是「固定金额」**，且：
>
> ```
> Σ(单价 × 数量) + 运费 − 折扣 + 税费 == 固定金额
> ```
>
> 后台保存时会拦一次并告诉你差多少；下单时 `OrderCreationService` 还会按同一条公式再算一遍（2.1 节铁律）。

### ⚠️ 商品明细和电商站点的关系

链接上配的商品**不一定**就是同步给电商站点的商品，取决于所选支付组里命中的支付方式的**商品匹配模式**：

| 匹配模式 | 链接上配的商品去了哪 |
|---|---|
| `MATCH` / `VIRTUAL` | 只用于**收款页展示**和本地 `order_items`。同步给站点的是 `OrderItemService` 从站点商品里随机凑出来的 `order_matched_items` |
| `CREATE` / `COPY` | 会拿去站点建同价商品 |

这是既有的下单逻辑，收款链接没有改变它。

---

## 二、客户端流程

```
客户打开 https://{域名}/c/{slug}
   ↓
看到 logo / 标题 / 商品小票 / 金额
   ↓
填 firstname、lastname、邮箱、手机号、收货地址 + 人机验证
   ↓
提交 → 服务端校验 → 建单（source='checkout_link'）
   ↓
302 跳转到支付网关收银台（orders.pay_url）
```

- **手机号**用 libphonenumber 按国家规则校验，必须带 `+` 国家码，落库前归一化成 E.164。
- **收货地址**接了 Google Places 自动填充，国家取 `short_name`（ISO alpha-2）。
- **页面固定英文**，不跟随 `APP_LOCALE`（面向海外买家）。文案在 `lang/en/checkout.php`。

**收款链接本身不处理支付状态。** `pending → paid` 一律由 `/api/webhooks/payment-gateway/status` 异步驱动，入账、商户通知、Telegram、广告转化上报全部沿用既有链路，零改动。

---

## 三、下单是怎么走的

服务端直接调 `OrderCreationService`（和后台手工建单的 `ManualOrderService` 同一个路子），**不走 HTTP 调 `/api/order/create`**。

> 为什么：那个接口用 App-ID + HMAC 签名鉴权，`api_key` 一旦下发到浏览器就等于公开，任何人都能伪造下单。落地页是公开页面，建单只能在服务端完成。

几个字段的取值规则：

| 字段 | 取值 |
|---|---|
| `source` | `checkout_link` |
| `platform` | `invoice`（`source` 和 `checkout_link_id` 用于识别收款链接订单） |
| `checkout_link_id` | 产生该订单的链接 ID，用于回答"哪条链接转化好" |
| `merchant_order_no` | 每次提交生成新的随机值 `CL{id}-{时间}-{随机}` |
| `return_url` / `cancel_url` | 系统生成，指向 `/c/{slug}/success`、`/c/{slug}/cancelled`，域名跟随链接绑定的商户域名 |
| `items` | 没配商品时合成一条兜底明细（名称取链接标题，单价 = 金额） |

`OrderCreationService` 第 4 步的**回跳域名一致性校验对 `checkout_link` 来源豁免**——三个回跳地址是系统生成的、指向落地页自己的域名，拿去比对 `applications.website` 必然不一致。该校验防的是"商户把回跳地址指到任意第三方站点"，而收款链接的地址商户改不了，风险本身不存在。

---

## 四、防刷

落地页是整个系统里唯一「无鉴权 + 可写库 + 会真的去调支付网关」的入口，压了四层：

| 层 | 挡什么 | 配置 |
|---|---|---|
| Cloudflare Turnstile | 自动化脚本 | `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` |
| 蜜罐字段 | 填满所有输入框的爬虫 | 无需配置 |
| 最短填表耗时 | 秒填秒交的脚本 | `CHECKOUT_MIN_FORM_SECONDS`（默认 3 秒） |
| IP / 邮箱 / 链接频率限制 | 人肉刷单、撞库试探 | `CHECKOUT_THROTTLE_*`，见 `config/checkout.php` |

任何一层单独都能被绕过，叠起来才有意义。

> ⚠️ **Turnstile 未配置密钥时校验直接放行**（方便本地开发）。生产环境忘配不会报错，但等于没开验证——上线前务必确认。

频率计数在**下单成功后**才累加，填错格式被打回的正常客户不消耗配额。

---

## 五、自定义域名

商户绑自己的域名需要走 Cloudflare for SaaS，详见 **[checkout-link-cloudflare-saas.md](./checkout-link-cloudflare-saas.md)**。

两条对商户的硬要求：
1. 用子域名（`checkout.example.com`），不能用根域名——根域名加不了 CNAME。
2. CNAME 记录必须是「仅 DNS」（灰云），开了代理证书签不下来。

没绑域名的商户，链接会跑在平台默认域名（`APP_URL`）上，功能完全可用。

收款链接还可以单独填写商户自己的 Google Maps 浏览器 Key。填写后收款页使用 Google Places 地址建议；不填写时使用国家、省州、城市联动选择。联动地区数据由本站向 CountriesNow 查询并缓存一天，服务不可用或地区缺失时客户仍可手动填写省州和城市。浏览器 Key 会公开在页面源码中，商户应在 Google Cloud 按实际收款页域名限制 HTTP referrer。

---

## 六、访问控制

收款页没有任何登录态，靠三道门：

1. **slug 不可猜测**：24 位随机串（和 `orders.payment_link_token` 同思路）。
2. **链接必须启用**：停用后立即 404。
3. **Host 白名单**：请求的 `Host` 必须是该链接绑定的域名或平台默认域名。防止 A 商户的域名被用来打开 B 商户的链接页面（钓鱼）。

三种失败一律返回 404 而不是更具体的提示——对未鉴权的访问者，"链接不存在"和"链接已停用"不应该被区分出来，否则可以用来探测哪些 slug 真实存在。

---

## 七、相关文件

| 层 | 文件 |
|---|---|
| 模型 | `app/Models/CheckoutLink.php`、`CheckoutLinkItem.php`、`MerchantDomain.php` |
| 建单 | `app/Services/Checkout/CheckoutLinkOrderService.php` |
| 域名/证书 | `app/Services/Checkout/MerchantDomainService.php`、`CloudflareSaasService.php` |
| 人机验证 | `app/Services/Checkout/TurnstileService.php` |
| 落地页 | `app/Http/Controllers/CheckoutLinkController.php`、`resources/views/checkout/` |
| 表单校验 | `app/Http/Requests/CreateCheckoutOrderRequest.php`、`app/Rules/InternationalPhone.php` |
| 后台 | `app/Filament/Resources/CheckoutLinkResource.php`、`MerchantDomainResource.php` |
| 路由 | `routes/checkout.php` |
| 配置 | `config/checkout.php`、`config/services.php` |
| 测试 | `tests/Feature/CheckoutLinkTest.php`、`tests/Feature/Filament/CheckoutLinkFormTest.php` |

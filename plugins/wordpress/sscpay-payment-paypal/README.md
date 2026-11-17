# SSCPay Payment - PayPal（WooCommerce 支付网关）

对接 SSCPay 订单系统的 WooCommerce 支付插件。下单时调用 `/api/order/create` 取得支付链接并跳转，支付结果由 webhook 异步通知，并有定时查单兜底。

- WordPress ≥ 6.0，PHP ≥ 7.4，WooCommerce ≥ 8.0
- 已声明 HPOS（高性能订单存储）与区块结账兼容
- 支持多币种（直接使用订单币种下单）

---

## 一、安装

把整个 `sscpay-payment-paypal` 目录放进 `wp-content/plugins/`，在「插件」页启用，然后到
**WooCommerce → 设置 → 付款 → SSCPay - PayPal** 配置。

## 二、配置项

### 前台展示
| 项 | 说明 |
|---|---|
| 支付方式名称 | 结账页显示的名称，随便改 |
| 支付方式描述 | 名称下方说明，支持简单 HTML |
| 支付方式 Logo | 走 WordPress 媒体库上传/选择，也可以直接粘贴图片地址 |
| Logo 显示高度 | 结账页图标高度，默认 24px |

### 订单系统接口
| 项 | 说明 |
|---|---|
| 沙箱模式 | 勾选后走沙箱那套 Base URL / App-ID / API Key，结账页会加「沙箱测试模式」提示 |
| 生产 / 沙箱 Base URL | 不带尾部斜杠，插件自动拼 `/api/order/create` 等路径 |
| 生产 / 沙箱 App-ID、API Key | 订单系统后台「应用管理」创建应用后生成 |

生产和沙箱是**两套独立凭据**，切换环境不会互相覆盖。订单会记下自己下单时所处的环境，
webhook 验签时按订单当时的环境取密钥，所以切换环境后老订单的回调依然验得过。

### 下单参数
| 项 | 说明 |
|---|---|
| `group_key` | 必填，订单系统后台「支付组」标识 |
| `payment_method_key` | 指定固定渠道（后台「支付方式」的 `method_code`）。填了会跳过支付组路由与限额风控，代价是三个回跳地址域名必须与该渠道绑定站点一致。留空则由支付组路由 |
| `platform` | 需在订单系统 `order.platforms` 枚举内，默认 `wordpress` |
| 商户订单号前缀 | 商户订单号 = 前缀 + WooCommerce 订单 ID。**多站点共用同一商户账号时务必设成不同前缀**，否则订单号会撞车 |

### 订单行为
| 项 | 说明 |
|---|---|
| 支付成功后的订单状态 | 默认交给 WooCommerce 判定（实物 → 处理中，虚拟可下载 → 已完成），也可强制指定 |
| 定时查单兜底 | 每小时查一次未完成订单的最终状态，**强烈建议开启**（见下方「五」） |
| 查单回溯窗口 | 只查这个时间范围内创建的待支付订单，默认 48 小时 |
| 调试日志 | 记录请求与回调报文到 WooCommerce 日志，来源 `sscpay_paypal`。API Key 等敏感字段自动脱敏 |

---

## 三、必须先做的一步：回调域名白名单

订单系统对 `notify_url` / `return_url` / `cancel_url` 做**精确 host 匹配**，不做大小写、
`www.`、端口号归一化。没加白名单的话下单会直接被拒，返回 `CALLBACK_DOMAIN_NOT_ALLOWED`。

插件的设置页顶部会把这两个地址原样打印出来，复制给订单系统管理员即可：

```
异步通知 notify_url： https://你的域名/?wc-api=sscpay_paypal_notify
同步跳转 return_url： https://你的域名/?wc-api=sscpay_paypal_return
```

（站点开了固定链接的话形式是 `https://你的域名/wc-api/sscpay_paypal_notify/`，以设置页显示的为准。）

`cancel_url` 用的是 WooCommerce 的订单付款页，host 与上面两个相同。

---

## 四、签名

出站请求与入站 webhook 验签用的是同一套算法，插件里只实现了一份
（`includes/class-sscpay-paypal-signature.php`）：

```
StringToSign = App-ID + "\n" + Timestamp + "\n" + X-Nonce + "\n" + 规范化请求体（不含 sign）
Signature    = HMAC-SHA256(StringToSign, api_key)   小写十六进制
```

规范化：移除 `sign` → 关联数组按 key 递归 `ksort`、数字索引列表保持原序 →
`json_encode(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`。

几个容易踩的点，插件里已经处理好了：

- **全程使用 PHP 数组，不用 `stdClass`。** 服务端拿到 JSON 后是 `json_decode($raw, true)`
  转成数组再重新编码的，空对象 `{}` 到它那边会变成 `[]`。我们这边同样编码成 `[]`，两边才逐字节一致。
- **金额一律传字符串**（`"190.00"` 而不是 `190.00`），只有 `quantity` 是整数。
- **读请求头时不做 `sanitize_text_field`。** WordPress 会给 `$_SERVER` 加转义，必须 `wp_unslash`
  拿回原值；任何改写都会导致签名对不上。
- **不对 webhook 的 `Timestamp` 做 ±5 分钟过期校验。** 重试间隔最长 1 小时，且每次重试的
  `Timestamp`/`X-Nonce`/`sign` 都是发送那一刻现算的，做过期校验会把正常重试判成非法请求。
  （反过来，我们调用订单系统时的 `Timestamp` 是有 ±5 分钟限制的，所以**服务器时钟要与标准时间同步**。）

签名对不上时，先开调试日志，`WooCommerce → 状态 → 日志` 里能看到插件算出来的规范化串。

---

## 五、订单状态是怎么同步的

订单系统**只在订单变为 `paid` 时推 webhook**，`failed` / `cancelled` / `expired` /
`refunded` / `chargeback` 等状态都不会通知。所以插件用三条路径同步状态：

1. **webhook**（`?wc-api=sscpay_paypal_notify`）— 支付成功的主路径。
   按 `order_no` 幂等，重复投递直接返回 200；回调金额/币种与订单不符时不会自动放行，
   而是把订单转 `on-hold` 并留言，等人工核对。10 秒内返回 2xx。
2. **同步跳转**（`?wc-api=sscpay_paypal_return`）— 用户付完款跳回来时，插件会先调一次
   `/order/query` 再进感谢页。文档明确说 `return_url` 只是展示、不可作为状态依据，所以这里必须查一次。
3. **定时查单**（WP-Cron，每小时）— 扫描窗口期内 `pending` / `on-hold` 的本网关订单，
   调 `/order/query` 补状态。`paid` → 完成付款；`failed` / `cancelled` / `expired` → 相应关单。
   已标记「需人工核对」的订单会跳过，不自动改状态。

> 站点访问量低时 WP-Cron 可能不准时。生产环境建议关掉 `DISABLE_WP_CRON` 伪 cron，
> 改用系统 crontab 定时打 `wp-cron.php`。

### 重复支付与网络重试
同一商城订单复用已保存的 `merchant_order_no`，网络失败不会另开一个订单号。已有组合付款继续使用原父单付款链接，保留已收款进度；部分付款与过期后的部分收款不自动确认付款或发货。

---

## 六、SSCPay 在线更新与 API v2

1.1.0 起，源码和发布流程在 `sscpay-server/plugins/wordpress/` 统一管理。更新器默认请求生产 API 根地址的 `/plugins/updates/v1/sscpay-payment-paypal`，生产地址为空时使用沙箱地址。设置页可单独填写 HTTPS 更新服务根地址；也可通过 `SSCPAY_UPDATE_BASE_URL` 或 `SSCPAY_PAYPAL_UPDATE_BASE_URL` 覆盖。无需填写 GitHub Token，已有 GitHub 配置保留在数据库但不再用于本插件更新；其他插件仍可能需要共用 Token。

插件列表保留「立即检查更新」。更新信息正常缓存六小时，错误缓存十五分钟；下载使用固定版本地址，并在安装前验证文件大小和 SHA-256。包内目录与主文件名保持不变。发布和旧仓库兼容镜像的操作见主项目 `doc/deployment.md` 的「电商插件发布与更新」。

### v2 组合付款

- 使用组合付款时清空「指定支付渠道」，并在对应支付组启用组合付款；已有指定配置不会自动清除。
- 建单、查单和物流继续使用 `/api/order/create`、`/api/order/query`、`/api/order/ship`，协议版本由服务端支付组决定，不另造 `/api/v2` 路径。
- V1、V2 普通单均兼容。完整 body（包括 `children`、null、字符串金额）参与原 HMAC 验签，每次通知必须有有效的新时间戳与 nonce。
- 组合单按 `event_id` 去重、按 `version` 保留最新进度。部分收款保持 `on-hold`；只有父单付清、金额币种及累计金额核对通过后才执行一次 WooCommerce 支付完成。
- 商城订单 `_sscpay_payment_progress` 保存父单类型、版本、状态、累计金额、余额和真实子单列表。`_sscpay_order_no` 始终保存商城关联的父单号。
- 商城物流集成可调用网关实例的 `sync_shipping( $order, $shipment )`，`$shipment` 使用主项目 API 的 `logistics_company`、`tracking_number`、`shipped_at` 字段。已付清组合单会逐个上传到真实已付子单；部分付款拒绝上传，不使用父单号调用发货接口。


### 商户物流回传接收

在本系统应用的「物流回传」配置中填写插件设置页显示的完整接收地址，通常为 `https://商户域名/wc-api/sscpay_shipment/`；实际地址以 WooCommerce 生成值为准。同一地址可接收 PayPal 与信用卡订单，按订单实际网关的凭据验证 App-ID 与完整请求签名。两个插件各自的 `_shipment` 接口也保留。

普通单按原商户订单号定位；组合单按父商户号和父系统号定位，保留子单引用。以「原商户号 + 运单号」去重，同一父单的多个子单共用一个运单时只新增一条 WooCommerce 订单备注及 `_sscpay_shipments` 记录，不改变支付状态，也不自动完成或发货。物流扩展可监听 `sscpay_paypal_shipment_received` 接入自己的展示与通知。

---

## 七、可用的钩子

```php
// 自定义下单报文
add_filter( 'sscpay_paypal_create_order_payload', function ( $payload, $order ) {
    $payload['platform'] = 'my-platform';
    return $payload;
}, 10, 2 );

// 组合付款进度通知可能尚未付清，不在此钩子发货
add_action( 'sscpay_paypal_payment_progress', function ( $order, $body, $changed ) {
    // 根据 paid_amount / remaining_amount 展示进度
}, 10, 3 );

// 父单或普通单确认 paid 后，保留原有回调钩子
add_action( 'sscpay_paypal_notify_processed', function ( $order, $body, $changed ) {
    // 例如：推 ERP、发通知
}, 10, 3 );

// 本地开发用自签证书时关掉校验（仅限本地！）
add_filter( 'sscpay_paypal_sslverify', '__return_false' );
```

---

## 八、排错速查

| 现象 | 先看哪里 |
|---|---|
| 结账页看不到这个支付方式 | 是否启用；Base URL / App-ID / API Key 是否都填了（缺一个 `is_available()` 就返回 false） |
| 区块结账页看不到，经典结账页正常 | 浏览器控制台是否有 JS 报错；`assets/js/blocks.js` 是否被安全插件/CDN 拦了 |
| 下单报 `401 Signature verification failed` | 服务器时钟是否与标准时间同步（±5 分钟）；App-ID / API Key 是否配错环境（沙箱 ↔ 生产） |
| 下单报 `CALLBACK_DOMAIN_NOT_ALLOWED` | 回调域名白名单没加，或 host 不完全一致（`www.` / 端口 / 大小写） |
| 下单报 `AMOUNT_MISMATCH` / `ITEMS_SUBTOTAL_MISMATCH` | 开调试日志看实际发出去的报文；通常是店铺装了改价类插件在结账后又动了金额 |
| 下单报 `PAYMENT_METHOD_DOMAIN_MISMATCH` | 填了 `payment_method_key`，但回跳地址域名与该渠道绑定站点不一致 |
| 用户付了款但订单还是待付款 | 白名单里 `notify_url` 是否加了；日志里有没有 `[回调]` 记录；等一次定时查单（最多 1 小时）看能否自动补上 |
| 订单变成「保留」并留言金额不符 | 回调金额与订单金额差超过 0.01，插件不自动放行，需人工核对后手动改状态 |

日志位置：**WooCommerce → 状态 → 日志**，来源选 `sscpay_paypal`。

---

## 九、已知边界

- 插件目前只实现了 `/order/create` 与 `/order/query`。`/order/ship`（同步物流）的客户端方法已经写好
  （`SSCPay_Paypal_Api_Client::ship_order()`），但没有接 WooCommerce 的发货动作 —— 需要的话可以在
  `woocommerce_order_status_completed` 之类的钩子里调用。
- 不支持后台退款按钮。订单系统的接口文档里没有退款接口，退款需要在订单系统后台操作，
  操作后 WooCommerce 这边不会自动同步（`refunded` 状态不触发 webhook，查单兜底目前也只处理
  `paid`/`failed`/`cancelled`/`expired`）。
- 不支持订阅 / 周期扣款。

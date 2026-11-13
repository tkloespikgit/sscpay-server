<?php

return [
    'title' => 'API 对接文档',
    'subtitle' => '订单创建、订单查询、物流上传与商户网站物流回传',
    'nav_label' => '目录',
    'language_label' => '切换语言',
    'skip_to_content' => '跳至正文',
    'copy_code' => '复制代码',
    'copied' => '已复制',
    'copy_failed' => '复制失败，请手动选择代码',
    'back_to_top' => '返回顶部',
    'anchor_label' => '复制或打开章节链接',
    'page_description' => '商户系统 API 参考：鉴权签名、创建订单、查询订单、上传物流，以及商户网站物流回传协议。',
    'reference' => [
        'currency_names' => [
            'USD' => '美元',
            'EUR' => '欧元',
            'JPY' => '日元',
            'GBP' => '英镑',
            'CAD' => '加拿大元',
            'AUD' => '澳大利亚元',
            'CHF' => '瑞士法郎',
            'CNY' => '人民币',
            'HKD' => '港元',
            'SGD' => '新加坡元',
            'NZD' => '新西兰元',
            'SEK' => '瑞典克朗',
            'NOK' => '挪威克朗',
            'DKK' => '丹麦克朗',
            'PLN' => '波兰兹罗提',
            'CZK' => '捷克克朗',
            'AED' => '阿联酋迪拉姆',
            'SAR' => '沙特里亚尔',
            'INR' => '印度卢比',
            'BRL' => '巴西雷亚尔',
            'MXN' => '墨西哥比索',
            'TWD' => '新台币',
            'KRW' => '韩元',
            'THB' => '泰铢',
            'MYR' => '马来西亚林吉特',
            'IDR' => '印度尼西亚卢比',
            'PHP' => '菲律宾比索',
            'VND' => '越南盾',
            'TRY' => '土耳其里拉',
            'ZAR' => '南非兰特',
        ],
        'platform_names' => [
            'wordpress' => 'WordPress / WooCommerce',
            'shopyy' => 'ShopYY',
            'shopline' => 'SHOPLINE',
            'invoice' => '发票 / 手工结账',
            'opencart' => 'OpenCart',
            'checkout_link' => '收款链接（历史值）',
        ],
        'unknown_currency' => '自定义配置币种',
        'unknown_platform' => '自定义配置平台',
        'empty_currencies' => '当前没有配置参考币种。请联系管理员确认建单币种和可用汇率。',
        'empty_platforms' => '当前没有配置电商平台。请联系管理员配置后再创建订单。',
    ],
    'sections' => [
        'overview' => [
            'title' => '对接总览',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '本文覆盖创建订单、查询订单、上传物流，以及本系统向商户网站回传物流。所有请求示例均为虚构数据，签名占位符必须替换为实时计算结果。',
                ],
                [
                    'type' => 'table',
                    'caption' => '接口与调用方向',
                    'headers' => ['用途', '方法 / 路径', '调用方向'],
                    'rows' => [
                        ['创建订单', 'POST /api/order/create', '商户 / ERP → 本系统'],
                        ['查询订单', 'POST /api/order/query', '商户 / ERP → 本系统'],
                        ['上传物流', 'POST /api/order/ship', '商户 / ERP → 本系统'],
                        ['商户物流回传', 'POST 应用配置的完整 HTTPS URL', '本系统 → 商户网站'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '开始前准备实际 API 域名、应用 App-ID / API Key、支付组 group_key 和承运商代码。币种、platform 及全部订单状态可从下方参考附录查看。示例中的 https://api.example.com 表示部署地址，不含 /api；请使用运维提供的 API 域名。',
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'reference-currencies',
                            'label' => '查看支持的币种',
                        ],
                        [
                            'anchor' => 'reference-platforms',
                            'label' => '查看电商平台',
                        ],
                        [
                            'anchor' => 'order-statuses',
                            'label' => '查看全部订单状态',
                        ],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => '建议对接顺序',
                    'text' => '创建订单 → 保存订单号和付款链接 → 引导客户支付 → 验证支付 webhook，必要时查询订单 → 发货后上传物流。浏览器回跳页面只用于展示，支付状态以已验签通知和查询结果为准。',
                    'tone' => 'info',
                ],
            ],
        ],
        'authentication' => [
            'title' => '鉴权与签名',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '创建订单、查询订单和上传物流使用同一组应用凭证。签名元信息放在请求头，sign 放在 JSON 请求体；Content-Type 使用 application/json，建议同时发送 Accept: application/json。支付通知和商户物流回传使用同一种 HMAC 算法，方向相反。',
                ],
                [
                    'type' => 'table',
                    'caption' => '请求头',
                    'headers' => ['名称', '必填', '规则'],
                    'rows' => [
                        ['App-ID', '是', '应用的 app_id。'],
                        ['Timestamp', '是', 'Unix 时间戳，单位秒；与服务器时间偏差不得超过 ±300 秒。'],
                        ['X-Nonce', '是', '每次实际请求唯一的随机字符串；调用本系统时 300 秒内不得重复，商户物流接收协议要求 600 秒内不得重复。'],
                        ['Content-Type', '是', 'application/json'],
                        ['Accept', '否', 'application/json'],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '待签名字符串',
                    'language' => 'text',
                    'code' => 'StringToSign = App-ID + "\\n" + Timestamp + "\\n" + X-Nonce + "\\n" + canonicalize(body_without_sign)
sign = HMAC-SHA256(key = API-Key, message = StringToSign)
Output: lowercase hexadecimal',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '规范化规则：移除顶层 sign；将对象（关联数组）按 key 递归字典序排序；列表保持原始顺序；JSON 序列化时不转义 Unicode 和斜杠，等价于 JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES。对最终要发送的字段和值签名，不要在签名后再修改请求体。',
                ],
                [
                    'type' => 'code',
                    'label' => 'PHP 参考：签名并查询订单',
                    'language' => 'php',
                    'code' => '<?php

function ksortRecursive(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $isList = array_is_list($value);
    $result = [];
    foreach ($value as $key => $item) {
        $result[$key] = ksortRecursive($item);
    }
    if (! $isList) {
        ksort($result);
    }

    return $result;
}

function canonicalize(array $body): string
{
    unset($body[\'sign\']);

    return json_encode(
        ksortRecursive($body),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function signRequest(
    string $appId,
    string $timestamp,
    string $nonce,
    array $body,
    string $apiKey
): string {
    $message = $appId . "\\n" . $timestamp . "\\n" . $nonce . "\\n" . canonicalize($body);

    return hash_hmac(\'sha256\', $message, $apiKey);
}

$appId = \'APP_YOUR_APPLICATION\';
$apiKey = \'YOUR_API_KEY\';
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$body = [\'merchant_order_no\' => \'M20261103001\'];
$body[\'sign\'] = signRequest($appId, $timestamp, $nonce, $body, $apiKey);

$ch = curl_init(\'https://api.example.com/api/order/query\');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        \'Content-Type: application/json\',
        \'Accept: application/json\',
        \'App-ID: \' . $appId,
        \'Timestamp: \' . $timestamp,
        \'X-Nonce: \' . $nonce,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
if ($response === false) {
    throw new RuntimeException(\'Request failed: \' . curl_error($ch));
}
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
',
                ],
                [
                    'type' => 'callout',
                    'title' => '重试时重新签名',
                    'text' => '每次请求都重新生成 Timestamp、X-Nonce 和 sign；创建订单重试仍复用原商户订单号、币种和原始金额。金额建议使用十进制字符串，避免不同语言的浮点序列化差异。不得将 API Key 放到浏览器或公开文档中。',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'code',
                    'label' => '鉴权失败：HTTP 401',
                    'language' => 'json',
                    'code' => '{
  "code": 401,
  "msg": "Signature verification failed."
}',
                ],
            ],
        ],
        'create-order' => [
            'title' => '创建订单',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '/api/order/create',
                    'direction' => '商户系统 → 本系统',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '创建订单并获取托管付款地址。新订单通常为 pending；成功建单不代表客户已经付款。每次请求只尝试一个渠道，网关建单失败立即返回“建单失败，请重试”。复用原商户订单号再次提交时，沿用原渠道和远端引用，不切换其他渠道。原渠道须仍启用且商户可用，普通路由还会检查原支付组及当前风控；不再可用时返回错误，即使存在其他可用渠道也不切换。已有付款链接或已收款的订单直接返回现有结果。',
                ],
                [
                    'type' => 'table',
                    'caption' => '请求字段',
                    'headers' => ['字段', '类型 / 限制', '必填', '说明'],
                    'rows' => [
                        ['merchant_order_no', 'string ≤64', '是', '商户订单号；与商户账号组合唯一，用于幂等。请保留原始字符串。'],
                        ['platform', 'string ≤50', '是', '电商平台类型，须属于后台配置的支持列表；wordpress 仅为示例。'],
                        ['currency', 'string, 3', '是', '三位币种代码，如 EUR、USD；附录列表仅供参考，实际还需可用汇率和渠道支持。'],
                        ['group_key', 'string ≤50', '是', '已启用且属于该商户的支付组标识；指定渠道时仍必填。'],
                        ['payment_method_key', 'string ≤50', '否', '指定渠道的 method_code。渠道必须启用且商户可用；跳过组内路由与限额筛选，失败不切换渠道。'],
                        ['subtotal', '数字字符串，≥0', '是', '商品小计；按两位小数须等于各商品 unit_price × quantity 之和。'],
                        ['shipping_fee', '数字字符串，≥0', '是', '运费。'],
                        ['discount', '数字字符串，≥0', '是', '减免金额，以正数表示。'],
                        ['tax', '数字字符串，≥0', '是', '税金。'],
                        ['amount', '数字字符串，≥0', '是', '应付金额：subtotal + shipping_fee - discount + tax；误差超过 0.01 拒绝。'],
                        ['customer', 'object', '是', '客户资料。'],
                        ['customer.first_name', 'string ≤100', '是', '名字。'],
                        ['customer.last_name', 'string ≤100', '是', '姓氏。'],
                        ['customer.email', 'email ≤255', '是', '客户电子邮箱。'],
                        ['customer.phone', 'string ≤30', '否', '客户电话。'],
                        ['shipping_address', 'object', '是', '收货地址。'],
                        ['shipping_address.line1', 'string ≤255', '是', '地址第一行。'],
                        ['shipping_address.line2', 'string ≤255', '否', '地址第二行。'],
                        ['shipping_address.city', 'string ≤100', '是', '城市。'],
                        ['shipping_address.state', 'string ≤100', '否', '州或省。'],
                        ['shipping_address.country', 'string, 2', '是', 'ISO 3166-1 alpha-2 国家代码，如 DE、US。'],
                        ['shipping_address.zip', 'string ≤20', '否', '邮政编码。'],
                        ['items', 'array, ≥1', '是', '至少一个商品。列表顺序参与签名，不可随意重排。'],
                        ['items[].product_sku', 'string ≤64', '否', '商户商品 SKU。'],
                        ['items[].product_id', 'string ≤64', '是', '商户侧商品唯一标识；WordPress 使用商品 product_id。'],
                        ['items[].product_url', 'URL ≤500', '是', '商品详情页链接。'],
                        ['items[].product_name', 'string ≤255', '是', '商品名称。'],
                        ['items[].product_description', 'string', '否', '商品描述。'],
                        ['items[].unit_price', '数字字符串，≥0', '是', '商品单价。'],
                        ['items[].quantity', 'integer ≥1', '是', '购买数量。'],
                        ['notify_url', 'URL ≤500', '是', '接收首次支付成功通知的地址。'],
                        ['return_url', 'URL ≤500', '是', '支付完成后的浏览器回跳地址；不能作为支付成功凭据。'],
                        ['cancel_url', 'URL ≤500', '是', '取消支付后的浏览器回跳地址。'],
                        ['send_mail', 'string: Y / N', '否', 'Y 请求发送付款链接邮件；N 或省略不发送。应用必须开启邮件并配齐发信账号和凭证。'],
                        ['ad_params', 'object', '否', '按 meta、google、tiktok 分组的广告追踪参数；配置对应应用凭证后可触发转化通知。'],
                        ['sign', 'string', '是', 'HMAC-SHA256 小写十六进制签名，放在 JSON 请求体中。'],
                    ],
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'reference-currencies',
                            'label' => '查看支持的币种',
                        ],
                        [
                            'anchor' => 'reference-platforms',
                            'label' => '查看电商平台',
                        ],
                        [
                            'anchor' => 'order-statuses',
                            'label' => '查看全部订单状态',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '请求体示例',
                    'language' => 'json',
                    'code' => '{
  "merchant_order_no": "M20261103001",
  "platform": "wordpress",
  "currency": "EUR",
  "group_key": "group_default",
  "subtotal": "180.00",
  "shipping_fee": "15.00",
  "discount": "10.00",
  "tax": "5.00",
  "amount": "190.00",
  "customer": {
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@example.com",
    "phone": "+4915112345678"
  },
  "shipping_address": {
    "line1": "Alexanderplatz 1",
    "line2": "Apt. 4B",
    "city": "Berlin",
    "state": "Berlin",
    "country": "DE",
    "zip": "10178"
  },
  "items": [
    {
      "product_sku": "SKU-1001",
      "product_id": "1001",
      "product_url": "https://merchant.example.com/products/keyboard",
      "product_name": "Mechanical Keyboard",
      "product_description": "Blue switches",
      "unit_price": "90.00",
      "quantity": 2
    }
  ],
  "notify_url": "https://merchant.example.com/api/payment/callback",
  "return_url": "https://merchant.example.com/order/success",
  "cancel_url": "https://merchant.example.com/order/cancel",
  "send_mail": "N",
  "sign": "SIGNATURE_PLACEHOLDER"
}',
                ],
                [
                    'type' => 'callout',
                    'title' => '金额与回跳地址',
                    'text' => 'amount = subtotal + shipping_fee - discount + tax，允许的最大误差为 0.01；商品小计按两位小数核对。notify_url、return_url、cancel_url 在普通和指定渠道模式下都必填。普通路由当前不要求这三个地址与应用官网域名一致；指定渠道时仍要求三个 URL 的域名与渠道绑定网站一致，比较忽略大小写、www. 前缀和端口。',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'code',
                    'label' => '成功响应：HTTP 200',
                    'language' => 'json',
                    'code' => '{
  "code": 0,
  "msg": "success",
  "data": {
    "order_no": "ORD202611031234561234",
    "order_type": "single",
    "payment_link_token": "EXAMPLE_PAYMENT_TOKEN",
    "payment_url": "https://api.example.com/payment/EXAMPLE_PAYMENT_TOKEN",
    "pay_url": "https://gateway.example.com/checkout/EXAMPLE",
    "converted_currency": "USD",
    "converted_amount": "205.20",
    "exchange_rate": "1.080000",
    "payment_method": "example_channel",
    "status": "pending",
    "order_status": "pending",
    "settlement_status": null,
    "shipping_status": "unshipped"
  }
}',
                ],
                [
                    'type' => 'table',
                    'caption' => '成功响应 data 字段',
                    'headers' => ['字段', '类型', '说明'],
                    'rows' => [
                        ['order_no', 'string', '系统订单号；保存以便查询和对账。'],
                        ['payment_link_token', 'string', '托管付款链接 token。'],
                        ['payment_url', 'string (URL)', '本系统的托管付款页，引导客户跳转该地址。'],
                        ['pay_url', 'string (URL) / null', '网关返回的付款链接。历史幂等订单缺少链接且已离开待支付流程时可能为空。'],
                        ['converted_currency', 'string', '折算币种，当前为 USD。'],
                        ['converted_amount', 'string', '按订单快照折算后的金额，包含配置的汇损。'],
                        ['exchange_rate', 'string', '下单锁定汇率，包含配置的汇损。'],
                        ['payment_method', 'string', '最终用于该订单的渠道。'],
                        ['status', 'string', '兼容状态：pending_settlement 返回 paid，其余状态原样返回。'],
                        ['order_status', 'string', '本地真实订单主状态，包含 pending_settlement（待结算）。'],
                        ['settlement_status', 'string / null', '真实结算记录状态 waiting_shipment、pending、settled、cancelled；尚无记录或历史订单为 null。'],
                        ['shipping_status', 'string', '有有效物流为 shipped，否则 unshipped；与结算进度独立。'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => '幂等与超时补单',
                    'text' => '幂等键是商户账号 + merchant_order_no。重复请求的币种和原始请求金额一致时复用旧订单；已有付款链接时直接返回，缺少链接时可补建。币种或原始金额不一致返回 HTTP 409 / ORDER_DETAILS_CONFLICT。超时或 GATEWAY_ERROR 后继续使用原单号重试，不要生成新单号。应用自动折扣与汇率只在首次建单时固定，后续幂等请求不会重新抽取折扣；查询金额可能与原始请求金额不同。',
                    'tone' => 'info',
                ],
                [
                    'type' => 'callout',
                    'tone' => 'warning',
                    'title' => '组合付款：必须先接入 V2 通知',
                    'text' => '应用开启组合付款后，未指定 payment_method_key 且订单 USD 总额超过支付组内所有可用渠道的单笔限额时，返回 order_type=combine。普通订单返回 single。创建组合订单仅保存父单，不创建子单或远端支付。商户必须把客户跳转到返回的组合收银台，不能自行预生成子单。关闭开关只影响新单，现有组合订单可继续付款。',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '收银台每轮先尝试一次付清余额；否则要求渠道日/月剩余额度能容纳其完整单笔上限且日笔数可用，从单笔限额最高的三个候选中等概率随机选一，收取该上限的 70%～90%。同一时刻只有一笔未决子单，确认成功后才开放下一笔；每轮重新选择渠道。下一轮无渠道时保留已付金额，稍后重试，不承诺建单时已预留后续全部额度。',
                ],
                [
                    'type' => 'table',
                    'caption' => '组合建单字段',
                    'headers' => [
                        '字段 / 规则',
                        '说明',
                    ],
                    'rows' => [
                        [
                            'order_type',
                            'single / combine',
                        ],
                        [
                            'order_no / merchant_order_no',
                            '父单系统号 / 商户原订单号。子单仅在客户发起付款时产生，商户子单号依次追加 -S001、-S002；该命名空间由父单保留。',
                        ],
                        [
                            'payment_url / pay_url',
                            '组合单两个字段都指向独立组合收银台；token 是付款链接凭证，不是 API Key。',
                        ],
                        [
                            'amount / converted_amount',
                            '原币应付总额 / USD 应付总额；应用自动折扣和汇率只在父单创建时确定，子单不重复折扣。',
                        ],
                        [
                            'paid_amount / paid_converted_amount',
                            '累计已收原币金额 / 累计已收 USD 金额，均为十进制字符串。USD 按累计折算差额分配尾差，子单之和严格等于父单。',
                        ],
                        [
                            'children',
                            '仅包含已创建的真实子单，初始为空，不含未来付款占位记录。',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '组合建单响应示例',
                    'language' => 'json',
                    'code' => '{
  "code": 0,
  "msg": "success",
  "data": {
    "order_type": "combine",
    "order_no": "CMB01JEXAMPLEPARENT00000000000",
    "merchant_order_no": "M20261113001",
    "status": "pending",
    "order_status": "pending",
    "platform": "wordpress",
    "currency": "USD",
    "amount": "4000.00",
    "converted_currency": "USD",
    "converted_amount": "4000.00",
    "exchange_rate": "1.000000",
    "paid_amount": "0.00",
    "paid_converted_amount": "0.00",
    "remaining_amount": "4000.00",
    "remaining_converted_amount": "4000.00",
    "payment_method": null,
    "settlement_status": null,
    "shipping_status": "unshipped",
    "current_order_no": null,
    "version": 0,
    "children": [],
    "created_at": "2026-11-13T11:55:00+08:00",
    "paid_at": null,
    "payment_link_token": "PUBLIC_PAYMENT_TOKEN",
    "payment_url": "https://pay.example.com/combine-payment/PUBLIC_PAYMENT_TOKEN",
    "pay_url": "https://pay.example.com/combine-payment/PUBLIC_PAYMENT_TOKEN"
  }
}',
                ],
            ],
        ],
        'query-order' => [
            'title' => '查询订单',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '/api/order/query',
                    'direction' => '商户系统 → 本系统',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '使用 POST JSON 查询当前商户名下订单。至少填写 order_no 或 merchant_order_no 中的一个；同时填写时按 AND 匹配，两个编号必须指向同一笔订单。编号在请求体中参与签名，不能改用未签名的 GET 查询参数。',
                ],
                [
                    'type' => 'table',
                    'caption' => '请求字段',
                    'headers' => ['字段', '类型 / 限制', '必填', '说明'],
                    'rows' => [
                        ['order_no', 'string', '二选一', '创建订单响应中的系统订单号。'],
                        ['merchant_order_no', 'string', '二选一', '创建订单时的商户订单号。'],
                        ['sign', 'string', '是', 'HMAC-SHA256 小写十六进制签名，放在 JSON 请求体中。'],
                    ],
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'order-statuses',
                            'label' => '查看全部订单状态',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '请求体示例',
                    'language' => 'json',
                    'code' => '{
  "merchant_order_no": "M20261103001",
  "sign": "SIGNATURE_PLACEHOLDER"
}',
                ],
                [
                    'type' => 'code',
                    'label' => '成功响应：HTTP 200',
                    'language' => 'json',
                    'code' => '{
  "code": 0,
  "msg": "success",
  "data": {
    "order_no": "ORD202611031234561234",
    "merchant_order_no": "M20261103001",
    "status": "paid",
    "order_status": "pending_settlement",
    "settlement_status": "pending",
    "shipping_status": "unshipped",
    "platform": "wordpress",
    "currency": "EUR",
    "amount": "190.00",
    "converted_amount": "205.20",
    "payment_method": "example_channel",
    "created_at": "2026-11-03T12:34:56+00:00"
  }
}',
                ],
                [
                    'type' => 'table',
                    'caption' => '成功响应 data 字段',
                    'headers' => ['字段', '类型', '说明'],
                    'rows' => [
                        ['order_no', 'string', '系统订单号。'],
                        ['merchant_order_no', 'string', '商户订单号。'],
                        ['status', 'string', '兼容状态：pending_settlement 返回 paid，其余状态原样返回。'],
                        ['order_status', 'string', '本地真实订单主状态，包含 pending_settlement（待结算）。'],
                        ['settlement_status', 'string / null', '真实结算记录状态 waiting_shipment、pending、settled、cancelled；尚无记录或历史订单为 null。'],
                        ['shipping_status', 'string', '有有效物流为 shipped，否则 unshipped；与结算进度独立。'],
                        ['platform', 'string / null', '下单平台类型。'],
                        ['currency', 'string', '订单币种。'],
                        ['amount', 'string', '实际应付金额，包含应用自动折扣的结果。'],
                        ['converted_amount', 'string', '按下单快照折算后的 USD 金额。'],
                        ['payment_method', 'string', '订单当前锁定的支付渠道。'],
                        ['created_at', 'string (ISO 8601)', '订单创建时间，带时区。'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => '只读本地状态',
                    'text' => '查询不会向支付网关发起实时请求，也不修改订单状态。响应不包含 pay_url 或付款 token；物流明细在 logistics / children[].logistics 中，请保存建单响应中的付款链接。跨商户订单和编号不匹配均返回 ORDER_NOT_FOUND。',
                    'tone' => 'info',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '使用父单 order_no 或原 merchant_order_no 查询组合单，返回总额、累计已付、剩余金额、所有真实子单及各自物流数据。使用子单系统号或带 -S001 等后缀的商户号查询子单，额外返回 parent_order_no / parent_merchant_order_no。单次查询仍只匹配当前商户；两个编号同时提供必须指向同一记录。',
                ],
                [
                    'type' => 'table',
                    'caption' => '组合查询状态',
                    'headers' => [
                        '字段 / 规则',
                        '说明',
                    ],
                    'rows' => [
                        [
                            'status=pending / order_status=partially_paid',
                            '已经部分付款，但原订单未支付成功，不能发货。',
                        ],
                        [
                            'status=paid',
                            '原币和 USD 剩余金额都为零，全部付清。',
                        ],
                        [
                            'expired / review',
                            '付款链接到期 / 需要核对，不可继续付款；已收款数据保留，不自动退款。迟到的有效成功回调仍会记录并通知。',
                        ],
                        [
                            'children[].payment_status',
                            'paid 表示该子单曾确认收款；退款、争议等看 order_status。未支付真实子单显示 pending 等实际状态。',
                        ],
                        [
                            'children[].logistics',
                            '未上传为 null；有物流时包含物流公司、单号、链接、发货时间及 sync_status。',
                        ],
                        [
                            'version',
                            '每新增一笔确认付款递增一次。只统计付款进度；后续售后状态通过查询读取。',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '组合订单查询：首笔已付',
                    'language' => 'json',
                    'code' => '{
  "code": 0,
  "msg": "success",
  "data": {
    "order_type": "combine",
    "order_no": "CMB01JEXAMPLEPARENT00000000000",
    "merchant_order_no": "M20261113001",
    "status": "pending",
    "order_status": "partially_paid",
    "platform": "wordpress",
    "currency": "USD",
    "amount": "4000.00",
    "converted_currency": "USD",
    "converted_amount": "4000.00",
    "exchange_rate": "1.000000",
    "paid_amount": "640.00",
    "paid_converted_amount": "640.00",
    "remaining_amount": "3360.00",
    "remaining_converted_amount": "3360.00",
    "payment_method": null,
    "settlement_status": null,
    "shipping_status": "unshipped",
    "current_order_no": null,
    "version": 1,
    "children": [
      {
        "sequence": 1,
        "order_no": "ORD202611130001",
        "merchant_order_no": "M20261113001-S001",
        "status": "paid",
        "order_status": "pending_settlement",
        "settlement_status": "pending",
        "shipping_status": "unshipped",
        "payment_status": "paid",
        "currency": "USD",
        "amount": "640.00",
        "converted_currency": "USD",
        "converted_amount": "640.00",
        "payment_method": "example_channel",
        "paid_at": "2026-11-13T12:00:00+08:00",
        "logistics": null
      }
    ],
    "created_at": "2026-11-13T11:55:00+08:00",
    "paid_at": null
  }
}',
                ],
            ],
        ],
        'upload-shipping' => [
            'title' => '上传物流',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '/api/order/ship',
                    'direction' => '商户 / ERP → 本系统',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '按商户订单号录入或覆盖物流。paid、pending_settlement、shipped、completed、partially_refunded、disputing 允许上传。发货后起算首次保存物流后进入 shipped 并起算，0 天周期可能直接 completed；支付后起算仍保持待结算或已完成。售后状态优先保留；其他状态返回 INVALID_ORDER_STATUS。',
                ],
                [
                    'type' => 'table',
                    'caption' => '请求字段',
                    'headers' => ['字段', '类型 / 限制', '必填', '说明'],
                    'rows' => [
                        ['merchant_order_no', 'string ≤80', '是', '按该应用所属商户定位订单，不跨商户查询。'],
                        ['logistics_company', 'string ≤100', '是', '后台已启用的 carrier_code，不是自由填写的公司名称；大小写不敏感。未登记的承运商需先联系管理员添加。'],
                        ['tracking_number', 'string ≤100', '是', '运单号；按字符串传递以保留前导零和长编号。'],
                        ['tracking_url', 'URL ≤255', '否', '客户查件链接。'],
                        ['shipped_at', 'date', '是', '发货时间，推荐带时区的 ISO 8601。'],
                        ['sign', 'string', '是', 'HMAC-SHA256 小写十六进制签名，放在 JSON 请求体中。'],
                    ],
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'order-statuses',
                            'label' => '查看全部订单状态',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '请求体示例',
                    'language' => 'json',
                    'code' => '{
  "merchant_order_no": "M20261103001",
  "logistics_company": "DHL",
  "tracking_number": "0001234567890123456789",
  "tracking_url": "https://tracking.example.com/?number=0001234567890123456789",
  "shipped_at": "2026-11-03T13:00:00+00:00",
  "sign": "SIGNATURE_PLACEHOLDER"
}',
                ],
                [
                    'type' => 'code',
                    'label' => '成功响应：HTTP 200',
                    'language' => 'json',
                    'code' => '{
  "code": 0,
  "msg": "success",
  "data": {
    "order_no": "ORD202611031234561234",
    "merchant_order_no": "M20261103001",
    "status": "shipped",
    "order_status": "shipped",
    "settlement_status": "pending",
    "shipping_status": "shipped",
    "logistics_company": "DHL",
    "tracking_number": "0001234567890123456789",
    "tracking_url": "https://tracking.example.com/?number=0001234567890123456789",
    "shipped_at": "2026-11-03T13:00:00+00:00"
  }
}',
                ],
                [
                    'type' => 'table',
                    'caption' => '成功响应 data 字段',
                    'headers' => ['字段', '类型', '说明'],
                    'rows' => [
                        ['order_no', 'string', '系统订单号。'],
                        ['merchant_order_no', 'string', '商户订单号。'],
                        ['status', 'string', '兼容状态：pending_settlement 返回 paid，其余状态原样返回。'],
                        ['order_status', 'string', '本地真实订单主状态，包含 pending_settlement（待结算）。'],
                        ['settlement_status', 'string / null', '真实结算记录状态 waiting_shipment、pending、settled、cancelled；尚无记录或历史订单为 null。'],
                        ['shipping_status', 'string', '有有效物流为 shipped，否则 unshipped；与结算进度独立。'],
                        ['logistics_company', 'string', '保存的承运商代码。'],
                        ['tracking_number', 'string', '保存的运单号。'],
                        ['tracking_url', 'string (URL) / null', '保存的查件地址。'],
                        ['shipped_at', 'string (ISO 8601)', '保存的发货时间。'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => '重复提交会覆盖物流',
                    'text' => '每订单只保留一条当前物流记录，补发、改单或同号重传均覆盖原记录。该语义不同于创建订单的幂等复用。上传成功只代表本地保存成功，不代表支付插件或商户网站已经同步成功；这些任务独立异步执行。',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '上传物流不会向 notify_url 发送支付结果 webhook。应用开启物流回传时，本次保存会另行推送到应用配置的商户网站；当前物流版本和回传尝试结果可在后台订单详情查看。',
                ],
                [
                    'type' => 'callout',
                    'tone' => 'warning',
                    'title' => '组合订单逐笔上传真实物流',
                    'text' => '父单全部付清后，以真实子单 merchant_order_no（例如 M20261113001-S001）逐笔调用本接口。传父单原订单号返回 CHILD_ORDER_REQUIRED；未付清返回 COMBINE_ORDER_NOT_PAID。每笔子单只同步到自己锁定的支付站点，接口成功仅代表本地保存，远端结果看 sync_status。商户订单号上传上限为 80 字符（建单原号仍为 64）。',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '一个真实包裹可能对应多个支付子单，可逐个提交相同的真实运单号，但现有支付插件协议可能以运单号去重并拒绝跨订单重复。需先让插件支持同号关联多订单；本系统不会虚构 -S001 运单号，也不把一次远端成功当作全部子单成功。',
                ],
            ],
        ],
        'merchant-shipment-sync' => [
            'title' => '商户物流回传',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '/api/fulfillment/shipment',
                    'direction' => '本系统 → 商户网站；此路径为接收地址示例',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '这是商户网站实现的接收接口，协议版本 v1.1。请在商户应用开启“物流回传”，填写完整的公网 HTTPS 地址，包括路径和需要的 query 参数；系统直接请求该地址，不自动拼接上方示例路径。回传使用订单所属应用当前配置的 App-ID / API Key，无需另配物流密钥。',
                ],
                [
                    'type' => 'table',
                    'caption' => '鉴权及接收要求',
                    'headers' => ['项目', '要求'],
                    'rows' => [
                        ['请求头', 'App-ID、Timestamp、X-Nonce；Content-Type: application/json。'],
                        ['签名', 'JSON body 中的 sign，复用前文 HMAC-SHA256 规范化算法。'],
                        ['时间窗口', 'Timestamp 为秒级 Unix 时间戳，允许 ±300 秒。'],
                        ['防重放', '接收方要求 X-Nonce 在 600 秒内不能重复；每次重试都会刷新时间戳、nonce 和签名。'],
                    ],
                ],
                [
                    'type' => 'table',
                    'caption' => '回传字段',
                    'headers' => ['字段', '类型 / 限制', '必填', '说明'],
                    'rows' => [
                        ['merchant_order_no', 'string', '是', '与创建订单时完全相同的商户订单号。'],
                        ['carrier', 'string', '是', '承运商名称，自由文本，如 DHL、USPS。'],
                        ['carrier_code', 'string', '否', '承运商标准代码；本系统有代码时一并传入。'],
                        ['tracking_number', 'string', '是', '本次运单号，保留前导零。'],
                        ['tracking_url', 'string (URL)', '否', '已有客户查件地址时发送。'],
                        ['shipped_at', 'string (ISO 8601)', '否', '接收协议允许省略并取接收时间；本系统会发送原始发货时间，带时区。'],
                        ['items', 'array', '否', '协议保留的部分发货商品列表；当前本系统不发送该字段。'],
                        ['items[].sku', 'string', '否', '可选商品 SKU，当前不发送。'],
                        ['items[].quantity', 'integer', '否', '可选本次发货数量，当前不发送。'],
                        ['sign', 'string', '是', 'HMAC-SHA256 小写十六进制签名，放在 JSON 请求体中。'],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '回传请求体示例',
                    'language' => 'json',
                    'code' => '{
  "merchant_order_no": "M20261103001",
  "carrier": "DHL",
  "carrier_code": "DHL",
  "tracking_number": "0001234567890123456789",
  "tracking_url": "https://tracking.example.com/?number=0001234567890123456789",
  "shipped_at": "2026-11-03T13:00:00+00:00",
  "sign": "SIGNATURE_PLACEHOLDER"
}',
                ],
                [
                    'type' => 'code',
                    'label' => '接收成功或同号重复：HTTP 200',
                    'language' => 'json',
                    'code' => '{
  "code": 0,
  "msg": "success"
}',
                ],
                [
                    'type' => 'table',
                    'caption' => '接收方错误响应',
                    'headers' => ['HTTP', 'code', '含义'],
                    'rows' => [
                        ['401', '1', '签名无效、缺少请求头、时间戳过期或 nonce 重复。'],
                        ['404', '2', '商户网站找不到对应 merchant_order_no。'],
                        ['422', '3', '必填字段缺失或格式不正确。'],
                        ['500', '9', '接收方内部错误，可按策略重试。'],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '接收失败示例：HTTP 404',
                    'language' => 'json',
                    'code' => '{
  "code": 2,
  "msg": "order not found"
}',
                ],
                [
                    'type' => 'callout',
                    'title' => '接收方幂等与改单',
                    'text' => '去重键为 merchant_order_no + tracking_number。同订单同运单号重试返回 HTTP 200 / code 0，不新增发货记录或重复发送客户邮件；不同运单号被视为新的一批发货并可通知客户。协议当前不支持覆盖或删除已接收记录，纠错需商户人工处理。本系统仍覆盖本地物流并继续回传，客户通知由商户网站自行决定。',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'table',
                    'caption' => '本系统发送策略',
                    'headers' => ['场景', '处理'],
                    'rows' => [
                        ['触发', '开关开启后，批量上传、后台录入、API 上传及改单保存成功后异步发送。'],
                        ['成功判定', '必须同时是 HTTP 200 和 JSON 整数 code = 0。'],
                        ['自动重试', '仅网络异常、超时、HTTP 429 / 5xx 重试；含首次最多 5 次，间隔为 30 秒、5 分钟、30 分钟、1 小时。'],
                        ['明确错误', '401 / 404 / 422 等其他响应直接终止；检查配置或字段后可手动再推送。'],
                        ['连续改单', '只尝试当前最新物流，旧版本停止待发和重试；重试保持原物流业务快照及发货时间。'],
                        ['关闭开关', '暂停尚未完成的发送；发送时检查应用当前开关和配置。'],
                        ['历史物流', '开启开关不自动补传历史；在订单详情有权限的用户可手动推送当前物流。'],
                        ['失败与提醒', '保存每次请求和响应。最终失败按商户合并 Telegram 提醒；未配置可用机器人时仍保留失败记录，提醒发送失败可再次尝试。'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '应用表单的“检测地址”仅以 HEAD 检查完整 URL 的连通性，不携带应用凭证或物流数据，不验证实际签名和发货处理。真实回传可在订单详情手动推送验证；操作可能触发商户网站客户通知。',
                ],
            ],
        ],
        'payment-notifications' => [
            'title' => '支付结果通知',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '创建订单时传入的 notify_url',
                    'direction' => '本系统 → 商户网站',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '普通单首次确认支付成功时创建支付结果通知，通知 status 固定为 paid；组合单按下方 V2 规则逐笔通知，与订单可能已经进入 pending_settlement 或 0 天周期 completed 无关。退款、拒付、争议、发货和结算等后续变化不创建这个通知，恢复正常状态也不重发首次通知。需要其他状态时使用订单查询。',
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'order-statuses',
                            'label' => '查看全部订单状态',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => '通知请求体示例',
                    'language' => 'json',
                    'code' => '{
  "order_no": "ORD202611031234561234",
  "merchant_order_no": "M20261103001",
  "status": "paid",
  "currency": "EUR",
  "amount": "190.00",
  "converted_currency": "USD",
  "converted_amount": "205.20",
  "payment_method": "example_channel",
  "occurred_at": "2026-11-03T12:40:00+00:00",
  "sign": "SIGNATURE_PLACEHOLDER"
}',
                ],
                [
                    'type' => 'table',
                    'caption' => '通知字段',
                    'headers' => ['字段', '说明'],
                    'rows' => [
                        ['order_no / merchant_order_no', '系统订单号 / 商户订单号。'],
                        ['status', '普通单为 paid；组合单未付清为 pending，付清后为 paid，到期或待核对可为 expired / review。'],
                        ['currency / amount', '订单币种和实际应付金额；金额是十进制字符串。'],
                        ['converted_currency / converted_amount', '折算币种与折算金额；当前折算币种为 USD。'],
                        ['payment_method', '实际收款渠道。'],
                        ['occurred_at', '首次通知触发时间，ISO 8601；不是网关原始支付时间。'],
                        ['sign', '使用应用 API Key 与前文规范化算法验证的签名。'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => '接收方必须验签并幂等',
                    'text' => '检查 App-ID、Timestamp、X-Nonce 并按前文算法计算预期签名，使用恒定时间比较（如 PHP hash_equals）验证 sign；保留 nonce 防重放。V1 按 order_no + status 幂等处理，V2 按 event_id 去重并按父单 version 更新进度，已处理过的重复通知直接返回成功，避免重复入账或发货。',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '在 10 秒内返回任意 HTTP 2xx 即确认支付通知成功，无需返回特定 JSON。其他状态、超时或连接失败会重试；默认含首次最多 5 次，间隔 30 秒、5 分钟、30 分钟、1 小时，可由系统配置调整。每次投递刷新时间戳、nonce 和签名，业务内容保持首次快照。',
                ],
                [
                    'type' => 'callout',
                    'title' => '两种通知的成功标准不同',
                    'text' => '支付结果通知接受任意 HTTP 2xx；商户物流回传要求严格 HTTP 200 且 JSON code 为整数 0。请分别处理，不要混用。',
                    'tone' => 'info',
                ],
                [
                    'type' => 'callout',
                    'tone' => 'warning',
                    'title' => 'V1 / V2 与验签兼容',
                    'text' => '未开启组合付款且使用 V1 的应用维持原通知体。开启组合付款自动使用 V2；V2 普通订单也带 order_type=single。新增字段会改变签名原文，验签算法不变：去掉 sign 后对收到的完整 JSON 对象递归规范化再签名，包含 children、null、paid_amount 等全部字段，不能用旧字段白名单重建请求体。',
                ],
                [
                    'type' => 'table',
                    'caption' => 'V2 通知规则',
                    'headers' => [
                        '字段 / 规则',
                        '说明',
                    ],
                    'rows' => [
                        [
                            'order_type=combine',
                            '每笔子单首次确认成功均发送一次通知。顶层订单号始终是父单 / 商户原号，amount 与 converted_amount 始终为父单总额；payment_method 为 null，实际方式在 children 内。',
                        ],
                        [
                            'paid_order_no / children',
                            '本次新增付款的子单系统号 / 事件发生时全部真实子单快照。未创建的下一笔不出现在数组内。',
                        ],
                        [
                            'status',
                            '部分付款为 pending，order_status=partially_paid；只有全部付清才是 paid。不能因为收到支付 webhook 就发货。',
                        ],
                        [
                            'event_id / version',
                            '按 event_id 去重；按父单和 version 防止旧重试覆盖新进度。重试保留原快照与 event_id，签名、时间戳、nonce 每次刷新。不能再按父单号 + pending 去重每笔事件。',
                        ],
                        [
                            'order_type=single',
                            '保留普通付款字段并新增 event_id、version=1、paid_order_no、累计金额、余额=0 和 children=[]。',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'V2 组合通知：首笔 640 USD 已付',
                    'language' => 'json',
                    'code' => '{
  "order_type": "combine",
  "order_no": "CMB01JEXAMPLEPARENT00000000000",
  "merchant_order_no": "M20261113001",
  "status": "pending",
  "order_status": "partially_paid",
  "platform": "wordpress",
  "currency": "USD",
  "amount": "4000.00",
  "converted_currency": "USD",
  "converted_amount": "4000.00",
  "exchange_rate": "1.000000",
  "paid_amount": "640.00",
  "paid_converted_amount": "640.00",
  "remaining_amount": "3360.00",
  "remaining_converted_amount": "3360.00",
  "payment_method": null,
  "settlement_status": null,
  "shipping_status": "unshipped",
  "current_order_no": null,
  "version": 1,
  "children": [
    {
      "sequence": 1,
      "order_no": "ORD202611130001",
      "merchant_order_no": "M20261113001-S001",
      "status": "paid",
      "order_status": "pending_settlement",
      "settlement_status": null,
      "shipping_status": "unshipped",
      "payment_status": "paid",
      "currency": "USD",
      "amount": "640.00",
      "converted_currency": "USD",
      "converted_amount": "640.00",
      "payment_method": "example_channel",
      "paid_at": "2026-11-13T12:00:00+08:00",
      "logistics": null
    }
  ],
  "created_at": "2026-11-13T11:55:00+08:00",
  "paid_at": null,
  "event_id": "bc7c186f-a106-4ec7-ad42-1bea374be103",
  "paid_order_no": "ORD202611130001",
  "occurred_at": "2026-11-13T12:00:01+08:00",
  "sign": "SIGNATURE_PLACEHOLDER"
}',
                ],
            ],
        ],
        'error-codes' => [
            'title' => '错误响应与排查',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '先检查 HTTP 状态，再检查业务 code / error_code。三个入站接口成功均为 HTTP 200、code 0；一般业务错误使用 code 表示 HTTP 状态，error_code 表示稳定的业务原因。签名无效时不会进入业务处理。',
                ],
                [
                    'type' => 'code',
                    'label' => '业务错误示例',
                    'language' => 'json',
                    'code' => '{
  "code": 404,
  "msg": "Order not found",
  "error_code": "ORDER_NOT_FOUND"
}',
                ],
                [
                    'type' => 'code',
                    'label' => '创建订单 / 上传物流字段错误：HTTP 422',
                    'language' => 'json',
                    'code' => '{
  "code": 422,
  "msg": "Validation failed",
  "errors": {
    "merchant_order_no": [
      "The merchant order no field is required."
    ]
  }
}',
                ],
                [
                    'type' => 'code',
                    'label' => '查询订单字段错误：HTTP 422',
                    'language' => 'json',
                    'code' => '{
  "message": "The order no field is required when merchant order no is not present. (and 1 more error)",
  "errors": {
    "order_no": [
      "The order no field is required when merchant order no is not present."
    ],
    "merchant_order_no": [
      "The merchant order no field is required when order no is not present."
    ]
  }
}',
                ],
                [
                    'type' => 'table',
                    'caption' => '本系统入站接口错误',
                    'headers' => ['HTTP', 'error_code', '接口', '说明'],
                    'rows' => [
                        ['401', '—', '全部', '缺少鉴权字段、应用不存在或已禁用、时间戳过期、nonce 重复、签名无效。'],
                        ['422', '—', '全部', '字段错误；创建/物流返回 code、msg、errors，查询返回 message、errors。错误文本可能随语言或字段不同。'],
                        ['422', 'AMOUNT_MISMATCH', '/api/order/create', '金额公式误差超过 0.01。'],
                        ['422', 'ITEMS_SUBTOTAL_MISMATCH', '/api/order/create', '商品明细合计与 subtotal 不一致。'],
                        ['422', 'MINIMUM_AMOUNT_NOT_MET', '/api/order/create', '折算 USD 金额不足以承担渠道手续费。'],
                        ['422', 'CALLBACK_DOMAIN_NOT_ALLOWED', '/api/order/create', '保留错误码；普通路由的应用网站域名一致性检查当前关闭。'],
                        ['422', 'PAYMENT_METHOD_NOT_AVAILABLE', '/api/order/create', '指定渠道不存在、不属于商户可用范围或已停用。'],
                        ['422', 'PAYMENT_METHOD_DOMAIN_MISMATCH', '/api/order/create', '指定渠道时三个回跳 URL 与渠道网站域名不一致。'],
                        ['409', 'NO_AVAILABLE_PAYMENT_METHOD', '/api/order/create', '支付组没有符合可用性及限额要求的渠道。'],
                        ['409', 'ORDER_DETAILS_CONFLICT', '/api/order/create', '同商户订单号的币种或原始请求金额与旧单不一致。'],
                        ['502', 'GATEWAY_ERROR', '/api/order/create', '网关付款链接创建失败；本地订单保留，可复用原商户单号重试。'],
                        ['404', '—', '/api/order/create', '支付组不存在、不属于商户或未启用，返回 code:404 和 msg。'],
                        ['404', 'ORDER_NOT_FOUND', '/api/order/query, /api/order/ship', '当前商户找不到订单，或查询的两个编号不同时匹配。'],
                        ['422', 'INVALID_ORDER_STATUS', '/api/order/ship', '订单状态不允许上传物流。'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => '失败后的处理',
                    'text' => '401 先检查时间同步、应用凭证和规范化算法；422 修正字段后重试；409 核对商户单号是否复用且金额币种一致；502 或超时保留原商户单号补单。每次重试都重新生成签名头，商户物流接收方的数值错误码另见物流回传章节。',
                    'tone' => 'info',
                ],
                [
                    'type' => 'table',
                    'caption' => '组合付款相关错误',
                    'headers' => [
                        '字段 / 规则',
                        '说明',
                    ],
                    'rows' => [
                        [
                            'COMBINE_PAYMENT_UNAVAILABLE (409)',
                            '当前没有满足完整单笔上限和日/月余额的拆单候选，稍后重试。',
                        ],
                        [
                            'CHILD_NUMBER_CONFLICT (409)',
                            '父子单号命名冲突，改用新的原商户订单号。',
                        ],
                        [
                            'CHILD_ORDER_REQUIRED (422)',
                            '物流上传必须使用真实子单号。',
                        ],
                        [
                            'COMBINE_ORDER_NOT_PAID (409)',
                            '原组合订单尚未全部付清，暂不允许上传物流。',
                        ],
                    ],
                ],
            ],
        ],
        'reference-currencies' => [
            'title' => '附录 A：支持的币种',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '下表根据当前部署的币种配置动态展示，作为对接参考。实际建单还需要该币种有可用汇率，并受所选支付渠道的币种能力限制；本列表不是接口字段层面的固定币种枚举。',
                ],
                [
                    'type' => 'table',
                    'caption' => '当前配置的币种参考',
                    'headers' => ['代码', '名称'],
                    'rows' => [],
                ],
            ],
        ],
        'reference-platforms' => [
            'title' => '附录 B：电商平台',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '下表来自当前部署的电商平台配置。创建订单的 platform 必须使用配置中的代码，不能用展示名称代替；配置调整后，此参考列表随之更新。',
                ],
                [
                    'type' => 'table',
                    'caption' => '当前配置的电商平台',
                    'headers' => ['代码', '名称'],
                    'rows' => [],
                ],
            ],
        ],
        'order-statuses' => [
            'title' => '附录 C：全部订单状态',
            'blocks' => [
                [
                    'type' => 'table',
                    'caption' => '对接状态参考',
                    'headers' => ['状态', '含义', '创建支付通知', '允许上传物流'],
                    'rows' => [
                        ['pending', '待支付', '否', '否'],
                        ['paid', '已支付，发货后起算待发货；兼容字段也表示待结算', '仅首次确认支付', '是'],
                        ['pending_settlement', '支付后起算待结算（仅 order_status）', '首次通知仍为 paid', '是'],
                        ['shipped', '已发货', '否', '是'],
                        ['completed', '本单已结算，仍允许售后', '否', '是'],
                        ['partially_refunded', '部分退款', '否', '是'],
                        ['refunded', '已全额退款', '否', '否'],
                        ['disputing', '争议中', '否', '是'],
                        ['dispute_review', '人工争议审核中', '否', '否'],
                        ['chargeback', '已拒付', '否', '否'],
                        ['failed', '支付失败', '否', '否'],
                        ['cancelled', '已取消', '否', '否'],
                        ['expired', '付款链接过期', '否', '否'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '不要把浏览器回跳、成功建单或成功上传物流当成首次支付成功。已完成表示实际结算完成，不代表不能退款、拒付或争议；物流与结算独立。内部网关查询只确认支付事实，不抹掉已记账售后或人工审核；商户查询接口始终只读，不发起网关查询或纠正。',
                ],
            ],
        ],
    ],
];

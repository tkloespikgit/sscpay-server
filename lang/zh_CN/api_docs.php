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
    'version_label' => '切换版本',
    'latest_version' => '最新',
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

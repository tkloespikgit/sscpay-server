<?php

$shared = require __DIR__.'/api_docs.php';

return [
    ...$shared,
    'sections' => [
        'overview' => [
            ...$shared['sections']['overview'],
            'blocks' => [
                [
                    'type' => 'callout',
                    'tone' => 'info',
                    'title' => '1.0 · 普通订单',
                    'text' => '适用于采用 V1 支付通知的普通订单。支付组关闭组合付款时，新普通单使用此协议。通知版本在建单时固定；本页版本选择只切换文档，不改变接口地址、支付组或订单配置。',
                ],
                ...$shared['sections']['overview']['blocks'],
            ],
        ],
        'authentication' => $shared['sections']['authentication'],
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
    "created_at": "2026-11-03T12:34:56+00:00",
    "payment_status": "paid",
    "converted_currency": "USD",
    "paid_at": "2026-11-03T12:40:00+00:00",
    "logistics": null
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
                        ['payment_status / paid_at / logistics', 'string / string / object / null', '付款事实、付款时间及当前物流；没有时间或物流时为 null。'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => '只读本地状态',
                    'text' => '查询不会向支付网关发起实时请求，也不修改订单状态。响应不包含 pay_url 或付款 token；物流明细在 logistics 中，请保存建单响应中的付款链接。跨商户订单和编号不匹配均返回 ORDER_NOT_FOUND。',
                    'tone' => 'info',
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
                        ['merchant_order_no', 'string ≤80', '是', '原始商户订单号，必须对应可上传物流的已付款订单。'],
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
                    'text' => '这是商户网站实现的接收接口，协议。请在商户应用开启“物流回传”，填写完整的公网 HTTPS 地址，包括路径和需要的 query 参数；系统直接请求该地址，不自动拼接上方示例路径。回传使用订单所属应用当前配置的 App-ID / API Key，无需另配物流密钥。',
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
                    'text' => '普通订单首次确认支付成功时创建支付结果通知，status 固定为 paid，与订单随后进入 pending_settlement 或 completed 无关。退款、拒付、争议、发货、结算及恢复正常状态不会重发此通知；其他状态请通过订单查询获取。',
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
                        ['status', '固定为 paid，表示首次确认支付成功。'],
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
                    'text' => '检查 App-ID、Timestamp、X-Nonce，对收到的完整 JSON（去掉 sign）计算签名，并使用恒定时间比较验证 sign；保留 nonce 防重放。按 order_no + status 幂等处理，已处理的通知直接返回成功，避免重复入账或发货。',
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
            ],
        ],
        'error-codes' => $shared['sections']['error-codes'],
        'reference-currencies' => $shared['sections']['reference-currencies'],
        'reference-platforms' => $shared['sections']['reference-platforms'],
        'order-statuses' => $shared['sections']['order-statuses'],
    ],
];

<?php

return [
    'title' => 'API Integration Reference',
    'subtitle' => 'Order creation, order queries, shipment uploads, and merchant shipment callbacks',
    'nav_label' => 'On this page',
    'language_label' => 'Language',
    'skip_to_content' => 'Skip to content',
    'copy_code' => 'Copy code',
    'copied' => 'Copied',
    'copy_failed' => 'Copy failed; select the code manually',
    'back_to_top' => 'Back to top',
    'anchor_label' => 'Open section link',
    'page_description' => 'Merchant API reference for authentication, order creation, order queries, shipment uploads, and merchant shipment callbacks.',
    'reference' => [
        'currency_names' => [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'JPY' => 'Japanese Yen',
            'GBP' => 'Pound Sterling',
            'CAD' => 'Canadian Dollar',
            'AUD' => 'Australian Dollar',
            'CHF' => 'Swiss Franc',
            'CNY' => 'Chinese Yuan',
            'HKD' => 'Hong Kong Dollar',
            'SGD' => 'Singapore Dollar',
            'NZD' => 'New Zealand Dollar',
            'SEK' => 'Swedish Krona',
            'NOK' => 'Norwegian Krone',
            'DKK' => 'Danish Krone',
            'PLN' => 'Polish Zloty',
            'CZK' => 'Czech Koruna',
            'AED' => 'UAE Dirham',
            'SAR' => 'Saudi Riyal',
            'INR' => 'Indian Rupee',
            'BRL' => 'Brazilian Real',
            'MXN' => 'Mexican Peso',
            'TWD' => 'New Taiwan Dollar',
            'KRW' => 'South Korean Won',
            'THB' => 'Thai Baht',
            'MYR' => 'Malaysian Ringgit',
            'IDR' => 'Indonesian Rupiah',
            'PHP' => 'Philippine Peso',
            'VND' => 'Vietnamese Dong',
            'TRY' => 'Turkish Lira',
            'ZAR' => 'South African Rand',
        ],
        'platform_names' => [
            'wordpress' => 'WordPress / WooCommerce',
            'shopyy' => 'ShopYY',
            'shopline' => 'SHOPLINE',
            'invoice' => 'Invoice / manual checkout',
            'opencart' => 'OpenCart',
            'checkout_link' => 'Legacy checkout link',
        ],
        'unknown_currency' => 'Custom configured currency',
        'unknown_platform' => 'Custom configured platform',
        'empty_currencies' => 'No reference currencies are configured. Ask the administrator to confirm order currencies and available exchange rates.',
        'empty_platforms' => 'No store platforms are configured. Ask the administrator to configure them before creating an order.',
    ],
    'sections' => [
        'overview' => [
            'title' => 'Integration overview',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'This reference covers order creation, order queries, shipment uploads, and shipment callbacks from this system to the merchant website. All examples use fictitious data; replace signature placeholders with freshly computed signatures.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Endpoints and request direction',
                    'headers' => ['Purpose', 'Method / path', 'Direction'],
                    'rows' => [
                        ['Create order', 'POST /api/order/create', 'Merchant / ERP → this system'],
                        ['Query order', 'POST /api/order/query', 'Merchant / ERP → this system'],
                        ['Upload shipment', 'POST /api/order/ship', 'Merchant / ERP → this system'],
                        ['Merchant shipment callback', 'POST to the full HTTPS URL configured on the application', 'This system → merchant website'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Before integrating, obtain the actual API host, application App-ID / API Key, payment group group_key, and carrier codes. Use the reference appendices below for currencies, platform values, and all order statuses. https://api.example.com represents the deployment origin without /api; use the API host supplied by the operator.',
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'reference-currencies',
                            'label' => 'View supported currencies',
                        ],
                        [
                            'anchor' => 'reference-platforms',
                            'label' => 'View store platforms',
                        ],
                        [
                            'anchor' => 'order-statuses',
                            'label' => 'View all order statuses',
                        ],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => 'Recommended integration sequence',
                    'text' => 'Create an order → save its reference and payment links → direct the customer to pay → verify payment webhooks and query the order when needed → upload shipment details after shipping. Browser redirects are for display only; use verified notifications and query results for payment status.',
                    'tone' => 'info',
                ],
            ],
        ],
        'authentication' => [
            'title' => 'Authentication and signatures',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Order creation, order queries, and shipment uploads use the same application credentials. Signature metadata belongs in HTTP headers and sign belongs in the JSON body. Use Content-Type: application/json and preferably Accept: application/json. Payment notifications and merchant shipment callbacks use the same HMAC algorithm in the opposite direction.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Request headers',
                    'headers' => ['Name', 'Required', 'Rule'],
                    'rows' => [
                        ['App-ID', 'Yes', 'The application app_id.'],
                        ['Timestamp', 'Yes', 'Unix timestamp in seconds; must be within ±300 seconds of server time.'],
                        ['X-Nonce', 'Yes', 'A new random value for each actual request. This system rejects reuse for 300 seconds; the merchant shipment receiver protocol requires a 600-second replay window.'],
                        ['Content-Type', 'Yes', 'application/json'],
                        ['Accept', 'No', 'application/json'],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'String to sign',
                    'language' => 'text',
                    'code' => 'StringToSign = App-ID + "\\n" + Timestamp + "\\n" + X-Nonce + "\\n" + canonicalize(body_without_sign)
sign = HMAC-SHA256(key = API-Key, message = StringToSign)
Output: lowercase hexadecimal',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Canonicalization: remove the top-level sign; recursively sort object keys; preserve list order; serialize JSON without escaping Unicode or slashes, equivalent to JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES. Sign the exact fields and values you will send, and do not modify the body afterward.',
                ],
                [
                    'type' => 'code',
                    'label' => 'PHP reference: sign and query an order',
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
                    'title' => 'Generate a fresh signature for every retry',
                    'text' => 'Generate new Timestamp, X-Nonce, and sign values for every request. A create-order retry must still use the original merchant order reference, currency, and requested amount. Use decimal strings for amounts to avoid cross-language floating-point serialization differences. Keep the API Key out of browsers and public documentation.',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'code',
                    'label' => 'Authentication failure: HTTP 401',
                    'language' => 'json',
                    'code' => '{
  "code": 401,
  "msg": "Signature verification failed."
}',
                ],
            ],
        ],
        'create-order' => [
            'title' => 'Create order',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '/api/order/create',
                    'direction' => 'Merchant system → this system',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Create an order and obtain hosted payment links. A new order is usually pending; successful creation does not mean the customer has paid. Each request attempts one channel and immediately returns GATEWAY_ERROR on a gateway creation failure. A new request with the original merchant reference reuses the original channel and gateway reference without switching channels. The channel must remain enabled and authorized; normal routing also rechecks the original group and current risk limits. An unavailable original channel returns an error even if other channels are available. Orders with an existing payment link or recorded payment return the existing result.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Request fields',
                    'headers' => ['Field', 'Type / limit', 'Required', 'Description'],
                    'rows' => [
                        ['merchant_order_no', 'string ≤64', 'Yes', 'Your order reference; unique within the merchant and used for idempotency. Preserve its exact string value.'],
                        ['platform', 'string ≤50', 'Yes', 'Store platform from the configured supported list; wordpress is only an example.'],
                        ['currency', 'string, 3', 'Yes', 'Three-letter currency code, such as EUR or USD. The appendix is a reference list; actual orders also require an available exchange rate and channel support.'],
                        ['group_key', 'string ≤50', 'Yes', 'Enabled payment group belonging to the merchant; still required for a designated channel.'],
                        ['payment_method_key', 'string ≤50', 'No', 'Designated channel method_code. It must be enabled and available to the merchant; skips group routing and amount-limit selection, with no failover.'],
                        ['subtotal', 'Numeric string, ≥0', 'Yes', 'Item subtotal; must equal the sum of unit_price × quantity at two decimal places.'],
                        ['shipping_fee', 'Numeric string, ≥0', 'Yes', 'Shipping charge.'],
                        ['discount', 'Numeric string, ≥0', 'Yes', 'Discount amount expressed as a positive value.'],
                        ['tax', 'Numeric string, ≥0', 'Yes', 'Tax amount.'],
                        ['amount', 'Numeric string, ≥0', 'Yes', 'Amount due: subtotal + shipping_fee - discount + tax; a difference greater than 0.01 is rejected.'],
                        ['customer', 'object', 'Yes', 'Customer details.'],
                        ['customer.first_name', 'string ≤100', 'Yes', 'First name.'],
                        ['customer.last_name', 'string ≤100', 'Yes', 'Last name.'],
                        ['customer.email', 'email ≤255', 'Yes', 'Customer email address.'],
                        ['customer.phone', 'string ≤30', 'No', 'Customer phone number.'],
                        ['shipping_address', 'object', 'Yes', 'Shipping address.'],
                        ['shipping_address.line1', 'string ≤255', 'Yes', 'Address line 1.'],
                        ['shipping_address.line2', 'string ≤255', 'No', 'Address line 2.'],
                        ['shipping_address.city', 'string ≤100', 'Yes', 'City.'],
                        ['shipping_address.state', 'string ≤100', 'No', 'State or province.'],
                        ['shipping_address.country', 'string, 2', 'Yes', 'ISO 3166-1 alpha-2 country code, such as DE or US.'],
                        ['shipping_address.zip', 'string ≤20', 'No', 'Postal code.'],
                        ['items', 'array, ≥1', 'Yes', 'At least one item. List order is part of the signature and must be preserved.'],
                        ['items[].product_sku', 'string ≤64', 'No', 'Merchant product SKU.'],
                        ['items[].product_id', 'string ≤64', 'Yes', 'Merchant product identifier; use the product_id for WordPress.'],
                        ['items[].product_url', 'URL ≤500', 'Yes', 'Product page URL.'],
                        ['items[].product_name', 'string ≤255', 'Yes', 'Product name.'],
                        ['items[].product_description', 'string', 'No', 'Product description.'],
                        ['items[].unit_price', 'Numeric string, ≥0', 'Yes', 'Item unit price.'],
                        ['items[].quantity', 'integer ≥1', 'Yes', 'Purchased quantity.'],
                        ['notify_url', 'URL ≤500', 'Yes', 'Endpoint that receives the first successful-payment notification.'],
                        ['return_url', 'URL ≤500', 'Yes', 'Browser return URL after payment; not proof of successful payment.'],
                        ['cancel_url', 'URL ≤500', 'Yes', 'Browser return URL after cancellation.'],
                        ['send_mail', 'string: Y / N', 'No', 'Y requests a payment-link email; N or omission does not. The application must enable email and have complete sending credentials.'],
                        ['ad_params', 'object', 'No', 'Ad tracking data grouped by meta, google, and tiktok; conversion delivery also requires the corresponding application credentials.'],
                        ['sign', 'string', 'Yes', 'Lowercase hexadecimal HMAC-SHA256 signature in the JSON body.'],
                    ],
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'reference-currencies',
                            'label' => 'View supported currencies',
                        ],
                        [
                            'anchor' => 'reference-platforms',
                            'label' => 'View store platforms',
                        ],
                        [
                            'anchor' => 'order-statuses',
                            'label' => 'View all order statuses',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'Example request body',
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
                    'title' => 'Amounts and callback URLs',
                    'text' => 'amount = subtotal + shipping_fee - discount + tax, with a maximum tolerated difference of 0.01; item subtotals are checked at two decimal places. notify_url, return_url, and cancel_url are mandatory for both routing modes. Normal routing currently does not require them to match the application website. A designated channel still requires all three URL hosts to match its bound website, ignoring case, a www. prefix, and the port.',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'code',
                    'label' => 'Success response: HTTP 200',
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
                    'caption' => 'Success response data fields',
                    'headers' => ['Field', 'Type', 'Description'],
                    'rows' => [
                        ['order_no', 'string', 'System order reference; save it for queries and reconciliation.'],
                        ['payment_link_token', 'string', 'Token for the hosted payment link.'],
                        ['payment_url', 'string (URL)', 'Hosted payment page in this system; direct the customer here.'],
                        ['pay_url', 'string (URL) / null', 'Gateway payment URL. May be null for an idempotently returned historical order without a link that has left the unpaid flow.'],
                        ['converted_currency', 'string', 'Converted currency, currently USD.'],
                        ['converted_amount', 'string', 'Converted amount from the order snapshot, including configured exchange surcharge.'],
                        ['exchange_rate', 'string', 'Exchange rate locked at creation, including the configured surcharge.'],
                        ['payment_method', 'string', 'Final channel used by the order.'],
                        ['status', 'string', 'Compatibility status: pending_settlement is returned as paid; other statuses are unchanged.'],
                        ['order_status', 'string', 'Actual local order status, including pending_settlement.'],
                        ['settlement_status', 'string / null', 'Actual settlement record: waiting_shipment, pending, settled, or cancelled; null when no record exists, including legacy orders.'],
                        ['shipping_status', 'string', 'shipped if an active shipment exists, otherwise unshipped; independent of settlement.'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => 'Idempotency and timeout recovery',
                    'text' => 'The idempotency key is merchant + merchant_order_no. Matching currency and original requested amount reuse the existing order: an existing payment link is returned, and a missing link may be created again. A different currency or original amount returns HTTP 409 / ORDER_DETAILS_CONFLICT. Reuse the original reference after a timeout or GATEWAY_ERROR instead of generating a new one. Application automatic discounts and exchange snapshots are fixed at first creation; retries do not redraw discounts, so the queried amount can differ from the original request.',
                    'tone' => 'info',
                ],
                [
                    'type' => 'callout',
                    'tone' => 'warning',
                    'title' => 'Combined payments require V2 notification handling',
                    'text' => 'When enabled on the application, an order without payment_method_key becomes order_type=combine if its USD total exceeds every eligible channel’s transaction limit in its payment group. Other orders return single. Creation stores only the parent, without child or remote orders. Redirect the customer to the returned combined checkout; do not precreate child payments. The checkout is English only. Once paid in full, it displays a completion message with no return-to-merchant link or automatic redirect; confirm the order through webhooks and queries. Disabling the switch affects new orders only.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Combined payments only allow MATCH/VIRTUAL product modes. COPY/CREATE are excluded from every payment, including the final balance; single orders may still use them. Keep the selected quote channel, or the latest paid child channel when there is no quote. Pay the balance in full if that channel permits it; otherwise charge a random 70–90% of its transaction cap. A higher cap or full-balance option elsewhere does not cause a switch. Select a channel only for the first payment or when the previous one fails availability, authorization, group, product-mode, capacity, count or fee checks. Selection keeps the existing rules: prefer full payment, otherwise choose uniformly among the three highest eligible caps with daily/monthly capacity for the full cap. An expired quote keeps its channel if eligible; recovery of an older channel does not switch it back. Only one unresolved child is allowed, and the next payment opens after confirmation. Retain paid progress if no channel is available; future capacity is not reserved.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Combined creation fields',
                    'headers' => [
                        'Field / rule',
                        'Description',
                    ],
                    'rows' => [
                        [
                            'order_type',
                            'single / combine',
                        ],
                        [
                            'order_no / merchant_order_no',
                            'Parent system reference / original merchant reference. Real children are created only when payment starts, using -S001, -S002 suffixes; that namespace is reserved for the parent.',
                        ],
                        [
                            'payment_url / pay_url',
                            'Both point to the independent combined checkout; its token is a payment-link credential, not an API key.',
                        ],
                        [
                            'amount / converted_amount',
                            'Total due in original currency / USD; automatic discount and exchange rate are fixed once on parent creation and not reapplied per child.',
                        ],
                        [
                            'paid_amount / paid_converted_amount',
                            'Cumulative gross paid in original currency / USD, as decimal strings. Cumulative conversion allocates cent differences so child totals equal the parent.',
                        ],
                        [
                            'children',
                            'Only real children already created; initially empty, with no future-payment placeholders.',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'Combined creation response',
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
            'title' => 'Query order',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '/api/order/query',
                    'direction' => 'Merchant system → this system',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Query an order belonging to the current merchant using POST JSON. Provide order_no, merchant_order_no, or both. When both are present, they are matched with AND and must refer to the same order. References belong in the signed request body, not unsigned GET parameters.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Request fields',
                    'headers' => ['Field', 'Type / limit', 'Required', 'Description'],
                    'rows' => [
                        ['order_no', 'string', 'One of the two', 'System reference returned when creating the order.'],
                        ['merchant_order_no', 'string', 'One of the two', 'Merchant reference supplied when creating the order.'],
                        ['sign', 'string', 'Yes', 'Lowercase hexadecimal HMAC-SHA256 signature in the JSON body.'],
                    ],
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'order-statuses',
                            'label' => 'View all order statuses',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'Example request body',
                    'language' => 'json',
                    'code' => '{
  "merchant_order_no": "M20261103001",
  "sign": "SIGNATURE_PLACEHOLDER"
}',
                ],
                [
                    'type' => 'code',
                    'label' => 'Success response: HTTP 200',
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
                    'caption' => 'Success response data fields',
                    'headers' => ['Field', 'Type', 'Description'],
                    'rows' => [
                        ['order_no', 'string', 'System order reference.'],
                        ['merchant_order_no', 'string', 'Merchant order reference.'],
                        ['status', 'string', 'Compatibility status: pending_settlement is returned as paid; other statuses are unchanged.'],
                        ['order_status', 'string', 'Actual local order status, including pending_settlement.'],
                        ['settlement_status', 'string / null', 'Actual settlement record: waiting_shipment, pending, settled, or cancelled; null when no record exists, including legacy orders.'],
                        ['shipping_status', 'string', 'shipped if an active shipment exists, otherwise unshipped; independent of settlement.'],
                        ['platform', 'string / null', 'Store platform.'],
                        ['currency', 'string', 'Order currency.'],
                        ['amount', 'string', 'Actual amount due, after any application automatic discount.'],
                        ['converted_amount', 'string', 'USD amount converted using the creation snapshot.'],
                        ['payment_method', 'string', 'Payment channel currently locked to the order.'],
                        ['created_at', 'string (ISO 8601)', 'Order creation time with a timezone offset.'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => 'Reads local state only',
                    'text' => 'This endpoint does not query the payment gateway in real time or change order state. It does not return pay_url or a payment token; save payment links from the creation response. Shipment details are included in logistics / children[].logistics. Cross-merchant orders and mismatching references both return ORDER_NOT_FOUND.',
                    'tone' => 'info',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Query by parent order_no or the original merchant_order_no to receive totals, cumulative paid amounts, remaining balances, all real children and their shipping data. Query a child by system reference or suffixed merchant reference to receive parent_order_no / parent_merchant_order_no. Both identifiers, when supplied, must match the same record within the current merchant.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Combined query states',
                    'headers' => [
                        'Field / rule',
                        'Description',
                    ],
                    'rows' => [
                        [
                            'status=pending / order_status=partially_paid',
                            'Partially paid; the original order is not paid and must not be fulfilled.',
                        ],
                        [
                            'status=paid',
                            'Both original-currency and USD balances are zero; paid in full.',
                        ],
                        [
                            'expired / review',
                            'Expired checkout / review required; no further payment. Collected funds remain recorded and are not automatically refunded. Valid late confirmations are still recorded and notified.',
                        ],
                        [
                            'children[].payment_status',
                            'paid means that child has a confirmed receipt; use order_status for refunds and disputes. Unpaid real children show their actual pending or other state.',
                        ],
                        [
                            'children[].logistics',
                            'null before shipping; otherwise includes carrier, tracking number, URL, shipment time and sync_status.',
                        ],
                        [
                            'version',
                            'Increments for each newly confirmed payment. This is a payment progress version; query for later after-sales state changes.',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'Combined query: first payment received',
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
            'title' => 'Upload shipment',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '/api/order/ship',
                    'direction' => 'Merchant / ERP → this system',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Record or replace shipment details for paid, pending_settlement, shipped, completed, partially_refunded, or disputing orders. Shipment-based settlement starts with the first active shipment and advances to shipped; a zero-day cycle may immediately complete. Payment-based orders remain awaiting settlement or completed. After-sale statuses take precedence. Other statuses return INVALID_ORDER_STATUS.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Request fields',
                    'headers' => ['Field', 'Type / limit', 'Required', 'Description'],
                    'rows' => [
                        ['merchant_order_no', 'string ≤80', 'Yes', 'Locates the order within the application merchant only.'],
                        ['logistics_company', 'string ≤100', 'Yes', 'An enabled carrier_code from the carrier list, not a free-text company name; case-insensitive. Ask an administrator to add an unknown carrier first.'],
                        ['tracking_number', 'string ≤100', 'Yes', 'Tracking number; send as a string to preserve leading zeros and long references.'],
                        ['tracking_url', 'URL ≤255', 'No', 'Customer tracking URL.'],
                        ['shipped_at', 'date', 'Yes', 'Shipment time; ISO 8601 with a timezone is recommended.'],
                        ['sign', 'string', 'Yes', 'Lowercase hexadecimal HMAC-SHA256 signature in the JSON body.'],
                    ],
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'order-statuses',
                            'label' => 'View all order statuses',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'Example request body',
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
                    'label' => 'Success response: HTTP 200',
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
                    'caption' => 'Success response data fields',
                    'headers' => ['Field', 'Type', 'Description'],
                    'rows' => [
                        ['order_no', 'string', 'System order reference.'],
                        ['merchant_order_no', 'string', 'Merchant order reference.'],
                        ['status', 'string', 'Compatibility status: pending_settlement is returned as paid; other statuses are unchanged.'],
                        ['order_status', 'string', 'Actual local order status, including pending_settlement.'],
                        ['settlement_status', 'string / null', 'Actual settlement record: waiting_shipment, pending, settled, or cancelled; null when no record exists, including legacy orders.'],
                        ['shipping_status', 'string', 'shipped if an active shipment exists, otherwise unshipped; independent of settlement.'],
                        ['logistics_company', 'string', 'Saved carrier code.'],
                        ['tracking_number', 'string', 'Saved tracking number.'],
                        ['tracking_url', 'string (URL) / null', 'Saved tracking URL.'],
                        ['shipped_at', 'string (ISO 8601)', 'Saved shipment time.'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => 'Repeated uploads replace the shipment',
                    'text' => 'Each order has one current shipment record. Reshipments, edits, and repeated uploads replace it. This differs from create-order idempotency. A successful upload means local persistence succeeded; it does not confirm delivery to the payment plugin or merchant website, which run asynchronously.',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Uploading a shipment does not send a payment-result webhook to notify_url. If application shipment callbacks are enabled, this save also queues a separate request to the configured merchant website. View the current shipment and callback results in the order details.',
                ],
                [
                    'type' => 'callout',
                    'tone' => 'warning',
                    'title' => 'Upload real tracking for each child',
                    'text' => 'After the parent is paid in full, call this endpoint separately for each real child merchant_order_no, for example M20261113001-S001. A parent reference returns CHILD_ORDER_REQUIRED; an unpaid parent returns COMBINE_ORDER_NOT_PAID. Each child syncs to its own locked payment site. Success confirms local persistence only; inspect sync_status for remote results. Shipping accepts up to 80 characters for suffixed references; creation still accepts 64.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'One real parcel may cover several payment children, so the same real tracking number can be submitted separately. The current payment plugin protocol may deduplicate tracking numbers across orders and reject this. The plugin must support one tracking number linked to multiple orders first. This system never invents suffixed tracking numbers or treats one remote success as success for all children.',
                ],
            ],
        ],
        'merchant-shipment-sync' => [
            'title' => 'Merchant shipment callbacks',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => '/api/fulfillment/shipment',
                    'direction' => 'This system → merchant website; this path is an example receiver endpoint',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'This is the merchant website receiver contract, version v1.1. Enable shipment callbacks on the merchant application and enter the full public HTTPS URL, including the path and required query parameters. The system requests that URL directly without appending the example path above. Callbacks use the current App-ID / API Key of the order application; no separate shipment secret is needed.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Authentication and receiver requirements',
                    'headers' => ['Item', 'Requirement'],
                    'rows' => [
                        ['Headers', 'App-ID, Timestamp, and X-Nonce; Content-Type: application/json.'],
                        ['Signature', 'sign in the JSON body using the canonical HMAC-SHA256 algorithm above.'],
                        ['Timestamp window', 'Timestamp is a Unix time in seconds, with ±300 seconds allowed.'],
                        ['Replay protection', 'The receiver must reject reused X-Nonce values within 600 seconds. Every retry refreshes its timestamp, nonce, and signature.'],
                    ],
                ],
                [
                    'type' => 'table',
                    'caption' => 'Callback fields',
                    'headers' => ['Field', 'Type / limit', 'Required', 'Description'],
                    'rows' => [
                        ['merchant_order_no', 'string', 'Yes', 'The exact merchant order reference used when creating the order. Combined payments use the original parent reference without a child suffix.'],
                        ['child_merchant_order_no', 'string', 'Combined only', 'Merchant reference of the child shipment, such as M20261113001-S001; omitted for single orders.'],
                        ['order_type', 'string', 'Combined only', 'Always combine for combined payments; omitted for single orders.'],
                        ['order_no', 'string', 'Combined only', 'System order reference of this child.'],
                        ['parent_order_no', 'string', 'Combined only', 'System order reference of the parent.'],
                        ['parent_merchant_order_no', 'string', 'Combined only', 'Original parent merchant reference, equal to merchant_order_no in this callback.'],
                        ['carrier', 'string', 'Yes', 'Carrier display name as free text, such as DHL or USPS.'],
                        ['carrier_code', 'string', 'No', 'Standard carrier code, included when available in this system.'],
                        ['tracking_number', 'string', 'Yes', 'Tracking number for this shipment, preserving leading zeros.'],
                        ['tracking_url', 'string (URL)', 'No', 'Included when a customer tracking URL is available.'],
                        ['shipped_at', 'string (ISO 8601)', 'No', 'The receiver may default an omitted value to receipt time; this system sends the original shipment time with a timezone.'],
                        ['items', 'array', 'No', 'Optional item list reserved by the receiver for partial shipments; this system currently omits it.'],
                        ['items[].sku', 'string', 'No', 'Optional product SKU; currently omitted.'],
                        ['items[].quantity', 'integer', 'No', 'Optional quantity in this shipment; currently omitted.'],
                        ['sign', 'string', 'Yes', 'Lowercase hexadecimal HMAC-SHA256 signature in the JSON body.'],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'Example callback request body',
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
                    'label' => 'Combined shipment callback example',
                    'language' => 'json',
                    'code' => '{
  "merchant_order_no": "M20261113001",
  "child_merchant_order_no": "M20261113001-S001",
  "order_type": "combine",
  "order_no": "ORD202611130001",
  "parent_order_no": "CMB202611130001",
  "parent_merchant_order_no": "M20261113001",
  "carrier": "DHL",
  "carrier_code": "DHL",
  "tracking_number": "REAL-TRACKING-001",
  "shipped_at": "2026-11-14T12:00:00+08:00",
  "sign": "SIGNATURE_PLACEHOLDER"
}',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Each child still sends a separate callback. merchant_order_no identifies the original parent order on the merchant website. When children share a tracking number, deduplicate by parent merchant reference + tracking number to avoid duplicate shipment records or customer notifications. Include child_merchant_order_no and all other added fields in signature verification. Uploads to /order/ship still use child merchant references. Existing callback snapshots remain unchanged; manually push from order details to generate a new snapshot in this format.',
                ],
                [
                    'type' => 'code',
                    'label' => 'Accepted or duplicate tracking number: HTTP 200',
                    'language' => 'json',
                    'code' => '{
  "code": 0,
  "msg": "success"
}',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Receiver error responses',
                    'headers' => ['HTTP', 'code', 'Meaning'],
                    'rows' => [
                        ['401', '1', 'Invalid signature, missing headers, expired timestamp, or reused nonce.'],
                        ['404', '2', 'The merchant website could not find merchant_order_no.'],
                        ['422', '3', 'Missing required fields or invalid format.'],
                        ['500', '9', 'Receiver internal error; eligible for retry.'],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'Receiver failure example: HTTP 404',
                    'language' => 'json',
                    'code' => '{
  "code": 2,
  "msg": "order not found"
}',
                ],
                [
                    'type' => 'callout',
                    'title' => 'Receiver idempotency and shipment edits',
                    'text' => 'The deduplication key is merchant_order_no + tracking_number. Retrying the same order and tracking number returns HTTP 200 / code 0 without adding a shipment or repeating a customer email. A different tracking number is a new shipment batch and may notify the customer. The receiver contract currently provides no update or delete operation; corrections require manual handling by the merchant. This system still replaces its local shipment and sends the new callback; the merchant website decides customer notification behavior.',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Delivery behavior in this system',
                    'headers' => ['Scenario', 'Behavior'],
                    'rows' => [
                        ['Trigger', 'When enabled, send asynchronously after successful bulk imports, manual entries, API uploads, and shipment edits.'],
                        ['Success', 'Requires both HTTP 200 and a JSON integer code of 0.'],
                        [
                            'Automatic retries',
                            'Only network failures, timeouts, HTTP 429, and 5xx are retried. At most five attempts including the first, with waits of 30 seconds, 5 minutes, 30 minutes, and 1 hour.',
                        ],
                        ['Other errors', 'Other responses, including 401 / 404 / 422, stop automatic retries. Correct configuration or data, then push manually if needed.'],
                        ['Successive edits', 'Only the latest shipment is attempted. Older pending deliveries and retries are stopped. Retries retain their shipment payload and original shipment time.'],
                        ['Disable callbacks', 'Pause unfinished delivery work. Each attempt checks the current application switch and configuration.'],
                        ['Existing shipments', 'Enabling callbacks does not backfill historical shipments. Authorized users can push the current shipment from order details.'],
                        [
                            'Failure records and alerts',
                            'Each request and response is recorded. Final failures are grouped into merchant Telegram alerts. Failure records remain when no usable bot exists; failed alert delivery can be attempted again.',
                        ],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'The application form Check Address button only sends HEAD to test full-URL connectivity. It carries no application credentials or shipment data and does not verify signatures or fulfillment processing. Test an actual callback by pushing from order details; this may trigger customer notifications on the merchant website.',
                ],
            ],
        ],
        'payment-notifications' => [
            'title' => 'Payment-result notifications',
            'blocks' => [
                [
                    'type' => 'endpoint',
                    'method' => 'POST',
                    'path' => 'notify_url supplied when creating the order',
                    'direction' => 'This system → merchant website',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'For single orders, a payment-result notification is created only when payment is first confirmed. Its status remains paid even when the local order has already entered pending_settlement or completed through a zero-day cycle. Later refunds, chargebacks, disputes, shipments, settlements, or recovery do not create another first-payment notification. Query orders for other status changes.',
                ],
                [
                    'type' => 'links',
                    'links' => [
                        [
                            'anchor' => 'order-statuses',
                            'label' => 'View all order statuses',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'Example notification body',
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
                    'caption' => 'Notification fields',
                    'headers' => ['Field', 'Description'],
                    'rows' => [
                        ['order_no / merchant_order_no', 'System / merchant order reference.'],
                        ['status', 'paid for single payments; combined progress uses pending until fully paid, with expired / review when stopped.'],
                        ['currency / amount', 'Order currency and actual amount due; the amount is a decimal string.'],
                        ['converted_currency / converted_amount', 'Converted currency and amount; the converted currency is currently USD.'],
                        ['payment_method', 'Channel used for payment.'],
                        ['occurred_at', 'ISO 8601 time when the original notification was created, not the gateway original payment time.'],
                        ['sign', 'Signature verified with the application API Key and the canonical algorithm above.'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => 'Verify signatures and process idempotently',
                    'text' => 'Check App-ID, Timestamp, and X-Nonce, compute the expected signature using the algorithm above, and compare sign in constant time (for example, PHP hash_equals). Track nonce values to prevent replay. For V1, process idempotently by order_no + status; for V2, deduplicate by event_id and compare parent version and acknowledge already-processed duplicates to avoid duplicate fulfillment or accounting.',
                    'tone' => 'warning',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Return any HTTP 2xx within 10 seconds to acknowledge a payment notification; no particular JSON body is required. Other statuses, timeouts, and connection failures are retried. The default is five total attempts with waits of 30 seconds, 5 minutes, 30 minutes, and 1 hour, adjustable by system configuration. Each attempt refreshes its timestamp, nonce, and signature while retaining the original business payload.',
                ],
                [
                    'type' => 'callout',
                    'title' => 'The two callback types have different acknowledgments',
                    'text' => 'Payment-result notifications accept any HTTP 2xx. Merchant shipment callbacks require exactly HTTP 200 with JSON integer code 0. Handle the two acknowledgment rules separately.',
                    'tone' => 'info',
                ],
                [
                    'type' => 'callout',
                    'tone' => 'warning',
                    'title' => 'V1 / V2 and signature compatibility',
                    'text' => 'Applications using V1 with combined payments disabled keep the original notification body. Enabling combined payments selects V2; V2 single orders also include order_type=single. New fields change the signed content, but not the algorithm: remove sign and recursively canonicalize the complete received JSON object, including children, null and paid_amount. Do not reconstruct the payload from an old field whitelist.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'V2 notification rules',
                    'headers' => [
                        'Field / rule',
                        'Description',
                    ],
                    'rows' => [
                        [
                            'order_type=combine',
                            'One event per newly confirmed child. Top-level references always identify the parent / original merchant order, and amount / converted_amount remain parent totals. payment_method is null; actual methods are inside children.',
                        ],
                        [
                            'paid_order_no / children',
                            'System reference of the child paid in this event / snapshot of all real children at event creation. Future, uncreated payments are absent.',
                        ],
                        [
                            'status',
                            'pending with order_status=partially_paid until fully paid; only then paid. Receiving a payment webhook alone must not trigger fulfillment.',
                        ],
                        [
                            'event_id / version',
                            'Deduplicate by event_id; compare version per parent to prevent older retries overwriting newer progress. Retries retain the snapshot and event_id while refreshing signature, timestamp and nonce. Parent reference + pending is not a valid per-payment deduplication key.',
                        ],
                        [
                            'order_type=single',
                            'Keeps ordinary payment fields and adds event_id, version=1, paid_order_no, cumulative paid amounts, zero balances and children=[].',
                        ],
                    ],
                ],
                [
                    'type' => 'code',
                    'label' => 'V2 combined notification: first USD 640 paid',
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
            'title' => 'Errors and troubleshooting',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Check the HTTP status first, then the business code / error_code. All three inbound endpoints return HTTP 200 and code 0 on success. General business errors use code for the HTTP status and error_code for the stable business reason. Invalid signatures are rejected before business processing.',
                ],
                [
                    'type' => 'code',
                    'label' => 'Example business error',
                    'language' => 'json',
                    'code' => '{
  "code": 404,
  "msg": "Order not found",
  "error_code": "ORDER_NOT_FOUND"
}',
                ],
                [
                    'type' => 'code',
                    'label' => 'Create-order / shipment field validation: HTTP 422',
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
                    'label' => 'Query-order field validation: HTTP 422',
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
                    'caption' => 'Inbound API errors',
                    'headers' => ['HTTP', 'error_code', 'Endpoint', 'Meaning'],
                    'rows' => [
                        ['401', '—', 'All', 'Missing authentication values, unknown or disabled application, expired timestamp, reused nonce, or invalid signature.'],
                        ['422', '—', 'All', 'Field validation: create/upload return code, msg, errors; query returns message, errors. Message text can vary by language and field.'],
                        ['422', 'AMOUNT_MISMATCH', '/api/order/create', 'Amount formula differs by more than 0.01.'],
                        ['422', 'ITEMS_SUBTOTAL_MISMATCH', '/api/order/create', 'Item total does not match subtotal.'],
                        ['422', 'MINIMUM_AMOUNT_NOT_MET', '/api/order/create', 'Converted USD amount is insufficient for the channel transaction fee.'],
                        ['422', 'CALLBACK_DOMAIN_NOT_ALLOWED', '/api/order/create', 'Reserved error code; normal-routing application-website matching is currently disabled.'],
                        ['422', 'PAYMENT_METHOD_NOT_AVAILABLE', '/api/order/create', 'Designated channel is unknown, unavailable to the merchant, or disabled.'],
                        ['422', 'PAYMENT_METHOD_DOMAIN_MISMATCH', '/api/order/create', 'One or more callback/return URL hosts do not match the designated channel website.'],
                        ['409', 'NO_AVAILABLE_PAYMENT_METHOD', '/api/order/create', 'No group channel passes availability and limit checks.'],
                        ['409', 'ORDER_DETAILS_CONFLICT', '/api/order/create', 'Currency or original requested amount conflicts with the existing merchant order reference.'],
                        ['502', 'GATEWAY_ERROR', '/api/order/create', 'Gateway payment-link creation failed. The local order remains; retry with the same merchant reference.'],
                        ['404', '—', '/api/order/create', 'Payment group is unknown, belongs to another merchant, or is disabled; response contains code:404 and msg.'],
                        ['404', 'ORDER_NOT_FOUND', '/api/order/query, /api/order/ship', 'No order in the current merchant, or the two query references do not match the same order.'],
                        ['422', 'INVALID_ORDER_STATUS', '/api/order/ship', 'Current order status does not allow a shipment upload.'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => 'Handling a failed request',
                    'text' => 'For 401, check clock synchronization, application credentials, and canonicalization. Correct fields for 422. For 409, verify reference reuse and matching amount/currency. After 502 or a timeout, recover with the same merchant reference. Refresh authentication values for every retry. Shipment receiver numeric error codes are documented in the shipment callback section.',
                    'tone' => 'info',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Combined payment errors',
                    'headers' => [
                        'Field / rule',
                        'Description',
                    ],
                    'rows' => [
                        [
                            'COMBINE_PAYMENT_UNAVAILABLE (409)',
                            'No combined-payment channel meets the product-mode and capacity requirements. Only MATCH/VIRTUAL are supported; COPY/CREATE are excluded. Adjust channel settings or retry later.',
                        ],
                        [
                            'CHILD_NUMBER_CONFLICT (409)',
                            'Parent/child reference namespace conflict; use another original merchant reference.',
                        ],
                        [
                            'CHILD_ORDER_REQUIRED (422)',
                            'Shipping requires a real child reference.',
                        ],
                        [
                            'COMBINE_ORDER_NOT_PAID (409)',
                            'The combined parent is not fully paid; shipping is not allowed yet.',
                        ],
                    ],
                ],
            ],
        ],
        'reference-currencies' => [
            'title' => 'Appendix A: Supported currencies',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'This reference list is populated from the current deployment currency configuration. An actual order also requires an available exchange rate and currency support from the selected payment channel. This list is not a fixed currency enum enforced by the API field validator.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Currently configured currency reference',
                    'headers' => ['Code', 'Name'],
                    'rows' => [],
                ],
            ],
        ],
        'reference-platforms' => [
            'title' => 'Appendix B: Store platforms',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'This list comes from the current deployment store-platform configuration. The create-order platform field must use a configured code, not its display name. Changes to the configuration are reflected in this reference.',
                ],
                [
                    'type' => 'table',
                    'caption' => 'Currently configured store platforms',
                    'headers' => ['Code', 'Name'],
                    'rows' => [],
                ],
            ],
        ],
        'order-statuses' => [
            'title' => 'Appendix C: All order statuses',
            'blocks' => [
                [
                    'type' => 'table',
                    'caption' => 'Integration status reference',
                    'headers' => ['Status', 'Meaning', 'Creates payment notification', 'Shipment upload allowed'],
                    'rows' => [
                        ['pending', 'Awaiting payment', 'No', 'No'],
                        ['paid', 'Paid, awaiting shipment for shipment-based terms; compatibility field also represents awaiting settlement', 'Only on first confirmed payment', 'Yes'],
                        ['pending_settlement', 'Payment-based order awaiting settlement (order_status only)', 'First notification remains paid', 'Yes'],
                        ['shipped', 'Shipped', 'No', 'Yes'],
                        ['completed', 'Settlement completed; after-sale events remain possible', 'No', 'Yes'],
                        ['partially_refunded', 'Partially refunded', 'No', 'Yes'],
                        ['refunded', 'Fully refunded', 'No', 'No'],
                        ['disputing', 'In dispute', 'No', 'Yes'],
                        ['dispute_review', 'Under manual dispute review', 'No', 'No'],
                        ['chargeback', 'Charged back', 'No', 'No'],
                        ['failed', 'Payment failed', 'No', 'No'],
                        ['cancelled', 'Cancelled', 'No', 'No'],
                        ['expired', 'Payment link expired', 'No', 'No'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Do not treat a browser return, successful order creation, or shipment upload as first-payment evidence. Completed means actual settlement has succeeded; refunds, chargebacks, and disputes remain possible. Shipment progress is independent. Internal gateway queries confirm payment facts without erasing posted reversals or active reviews. The merchant query endpoint remains read-only and does not contact the gateway.',
                ],
            ],
        ],
    ],
];

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
    'version_label' => 'Documentation version',
    'latest_version' => 'Latest',
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

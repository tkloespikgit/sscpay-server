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
                    'title' => '1.0 · Single orders',
                    'text' => 'For ordinary orders using V1 payment notifications. New single orders use this protocol when combined payments are disabled on their payment group. The protocol is saved at creation. This selector changes documentation only, not API endpoints, payment group settings or orders.',
                ],
                ...$shared['sections']['overview']['blocks'],
            ],
        ],
        'authentication' => $shared['sections']['authentication'],
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
                        ['payment_status / paid_at / logistics', 'string / string / object / null', 'Payment state, payment time and current shipment; missing time or shipment is null.'],
                    ],
                ],
                [
                    'type' => 'callout',
                    'title' => 'Reads local state only',
                    'text' => 'This endpoint does not query the payment gateway in real time or change order state. It does not return pay_url or a payment token; save payment links from the creation response. Shipment details are included in logistics. Cross-merchant orders and mismatching references both return ORDER_NOT_FOUND.',
                    'tone' => 'info',
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
                        ['merchant_order_no', 'string ≤80', 'Yes', 'The original merchant reference of an order eligible for shipment upload.'],
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
                    'text' => 'This is the merchant website receiver contract. Enable shipment callbacks on the merchant application and enter the full public HTTPS URL, including the path and required query parameters. The system requests that URL directly without appending the example path above. Callbacks use the current App-ID / API Key of the order application; no separate shipment secret is needed.',
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
                        ['merchant_order_no', 'string', 'Yes', 'The exact merchant reference supplied when creating the order.'],
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
                        ['Automatic retries', 'Only network failures, timeouts, HTTP 429, and 5xx are retried. At most five attempts including the first, with waits of 30 seconds, 5 minutes, 30 minutes, and 1 hour.'],
                        ['Other errors', 'Other responses, including 401 / 404 / 422, stop automatic retries. Correct configuration or data, then push manually if needed.'],
                        ['Successive edits', 'Only the latest shipment is attempted. Older pending deliveries and retries are stopped. Retries retain their shipment payload and original shipment time.'],
                        ['Disable callbacks', 'Pause unfinished delivery work. Each attempt checks the current application switch and configuration.'],
                        ['Existing shipments', 'Enabling callbacks does not backfill historical shipments. Authorized users can push the current shipment from order details.'],
                        ['Failure records and alerts', 'Each request and response is recorded. Final failures are grouped into merchant Telegram alerts. Failure records remain when no usable bot exists; failed alert delivery can be attempted again.'],
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
                    'text' => 'A payment-result notification is created only when payment is first confirmed. Its status is paid even if the local order has moved to pending_settlement or completed. Refunds, chargebacks, disputes, shipment, settlement and restoration do not create another payment-result notification; query the order for other states.',
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
                        ['status', 'Always paid, indicating the first confirmed payment.'],
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
                    'text' => 'Verify App-ID, Timestamp and X-Nonce. Compute the signature from the complete received JSON without sign and compare it in constant time. Track nonce values to prevent replay. Process idempotently by order_no + status and acknowledge duplicates without repeating fulfillment or accounting.',
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
            ],
        ],
        'error-codes' => $shared['sections']['error-codes'],
        'reference-currencies' => $shared['sections']['reference-currencies'],
        'reference-platforms' => $shared['sections']['reference-platforms'],
        'order-statuses' => $shared['sections']['order-statuses'],
    ],
];

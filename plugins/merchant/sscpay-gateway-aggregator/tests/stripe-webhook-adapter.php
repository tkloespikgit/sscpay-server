<?php
/** Run: php tests/stripe-webhook-adapter.php. Real adapter and signatures; isolated API services. */

namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    define('ABSPATH', dirname(__DIR__) . '/');
    function home_url($path = '') { return 'https://example.invalid' . $path; }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}

namespace PaymentGatewayAggregator\Tests\StripeWebhookAdapter {
    class Fixture
    {
        public static $requests = [];
        public static $logs = [];
        public static $response;

        public static function reset($response = null)
        {
            self::$requests = self::$logs = [];
            self::$response = $response;
        }

        public static function request($method, $params)
        {
            self::$requests[] = [$method, $params];
            if (self::$response instanceof \Throwable) { throw self::$response; }
            return self::$response;
        }
    }
    class Sessions
    {
        public function create($params, $options) { return Fixture::request('sessions.create', [$params, $options]); }
        public function all($params) { return Fixture::request('sessions.all', $params); }
    }
    class Disputes
    {
        public function retrieve($id) { return Fixture::request('disputes.retrieve', $id); }
    }
}

namespace Stripe {
    class StripeClient
    {
        public $checkout;
        public $disputes;
        public function __construct($key)
        {
            $this->checkout = (object) ['sessions' => new \PaymentGatewayAggregator\Tests\StripeWebhookAdapter\Sessions()];
            $this->disputes = new \PaymentGatewayAggregator\Tests\StripeWebhookAdapter\Disputes();
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    class LogManager
    {
        const SYSTEM_ORDER_ID = 'SYSTEM';
        public static function __callStatic($level, $args)
        {
            \PaymentGatewayAggregator\Tests\StripeWebhookAdapter\Fixture::$logs[] = [$level, $args];
        }
    }
}

namespace PaymentGatewayAggregator\Frontend {
    class PaymentPage
    {
        public static function resultPageUrl(array $transaction) { return 'https://example.invalid/result'; }
        public static function cancelPageUrl(array $transaction) { return 'https://example.invalid/cancel'; }
    }
}

namespace {
    use PaymentGatewayAggregator\Gateways\StripeGateway;
    use PaymentGatewayAggregator\Tests\StripeWebhookAdapter\Fixture;

    require dirname(__DIR__) . '/vendor/autoload.php';
    set_error_handler(function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) { throw new \ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    function same($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
    function mustThrow($callback)
    {
        try { $callback(); } catch (\RuntimeException $error) { return $error; }
        throw new \RuntimeException('Expected retryable API failure');
    }
    function body($type, array $object)
    {
        return json_encode(['id' => 'evt_fixture', 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
    }
    function gateway() { return new StripeGateway(['secret_key' => 'synthetic-key']); }
    function disputeEvent()
    {
        return gateway()->parseCallbackEvent(body('charge.dispute.created', [
            'id' => 'du_fixture', 'payment_intent' => 'pi_fixture', 'amount' => 2500,
            'currency' => 'usd', 'status' => 'needs_response', 'reason' => 'fraudulent',
        ]), []);
    }

    $tests = [];
    $tests['unrelated discounts mandates and PI creation never become order references'] = function () {
        foreach (['customer.discount.created' => 'di_fixture', 'mandate.updated' => 'mandate_fixture',
            'payment_intent.created' => 'pi_fixture', 'charge.succeeded' => 'ch_fixture'] as $type => $id) {
            same(false, gateway()->supportsWebhookEvent($type), 'Unsupported event');
            same([], gateway()->extractExternalRefs(body($type, ['id' => $id])), 'No order lookup candidates');
            same(null, gateway()->extractExternalRef(body($type, ['id' => $id]), []), 'Legacy API also ignores it');
        }
    };
    $tests['PI lookup preserves its ID and only accepts a Session-shaped order reference'] = function () {
        foreach (['INV-001', '', null, ['id' => 'cs_fixture']] as $reference) {
            same(['pi_fixture'], gateway()->extractExternalRefs(body('payment_intent.succeeded', [
                'id' => 'pi_fixture', 'payment_details' => ['order_reference' => $reference],
            ])), 'Merchant references cannot shadow the PI');
        }
        same(['pi_fixture', 'cs_test_fixture'], gateway()->extractExternalRefs(body('payment_intent.succeeded', [
            'id' => 'pi_fixture', 'payment_details' => ['order_reference' => 'cs_test_fixture'],
        ])), 'Both lookup paths retained');
    };
    $tests['Session Charge and Dispute use payment references rather than unrelated object IDs'] = function () {
        same(['cs_fixture', 'pi_fixture'], gateway()->extractExternalRefs(body('checkout.session.completed', [
            'id' => 'cs_fixture', 'payment_intent' => ['id' => 'pi_fixture'],
        ])), 'Expanded PI');
        same(['pi_fixture', 'ch_fixture'], gateway()->extractExternalRefs(body('charge.refunded', [
            'id' => 'ch_fixture', 'payment_intent' => 'pi_fixture',
        ])), 'Charge points at PI');
        same(['pi_fixture', 'ch_fixture'], gateway()->extractExternalRefs(body('charge.dispute.created', [
            'id' => 'du_fixture', 'payment_intent' => 'pi_fixture', 'charge' => 'ch_fixture',
        ])), 'Dispute ID never used as order identity');
    };
    $tests['completed unpaid retains PI association without confirming payment'] = function () {
        $event = gateway()->parseCallbackEvent(body('checkout.session.completed', [
            'id' => 'cs_fixture', 'payment_intent' => 'pi_fixture', 'payment_status' => 'unpaid',
        ]), []);
        same('processing', $event['event_status'], 'Still awaiting settlement');
        same('pi_fixture', $event['transaction_id'], 'PI available for verified alias persistence');
        same(null, $event['amount_minor'], 'No paid amount claim');
    };
    $tests['asynchronous Session success and failure normalize to actionable payment events'] = function () {
        foreach (['succeeded' => 'paid', 'failed' => 'failed'] as $suffix => $status) {
            $type = 'checkout.session.async_payment_' . $suffix;
            same(true, gateway()->supportsWebhookEvent($type), 'Required event supported');
            $event = gateway()->parseCallbackEvent(body($type, [
                'id' => 'cs_fixture', 'payment_intent' => 'pi_fixture', 'amount_total' => 2500, 'currency' => 'usd',
            ]), []);
            same($status, $event['event_status'], 'Settlement outcome');
            same(2500, $event['amount_minor'], 'Amount for validation');
            same('USD', $event['currency'], 'Currency for validation');
            same('pi_fixture', $event['transaction_id'], 'PI association');
        }
    };
    $tests['partial refunds retain cumulative amount without marking the whole order refunded'] = function () {
        $object = ['id' => 'ch_fixture', 'payment_intent' => 'pi_fixture', 'amount' => 2500,
            'amount_refunded' => 500, 'currency' => 'usd', 'refunded' => false];
        $event = gateway()->parseCallbackEvent(body('charge.refunded', $object), []);
        same('partial_refund', $event['event_status'], 'Partial remains distinct');
        same(2500, $event['amount_minor'], 'Original amount retained for order verification');
        same(500, $event['refund_amount_minor'], 'Cumulative refund available');
        same('USD', $event['currency'], 'Currency retained');
        $object['amount_refunded'] = 2500;
        same('refunded', gateway()->parseCallbackEvent(body('charge.refunded', $object), [])['event_status'], 'Full cumulative refund');
    };
    $tests['creation includes stable attempt metadata on Session and deferred PI without leaking S order ID'] = function () {
        Fixture::reset((object) ['id' => 'cs_fixture', 'url' => 'https://example.invalid/stripe',
            'payment_intent' => null, 'created' => 100, 'expires_at' => 200]);
        $result = gateway()->preparePayment([
            's_order_id' => 'S-INTERNAL-SECRET', 'currency' => 'USD', 'amount' => '25.00',
            'shipping_fee' => 0, 'tax_fee' => 0, 'discount_fee' => 0, 'customer_email' => 'buyer@example.invalid',
            'invoice_number' => 'INV-001', 'payment_attempt_key' => 'opaque-attempt-fixture',
            'stripe_metadata_version' => 1,
            'items' => [['name' => 'Item', 'sku' => 'SKU', 'quantity' => 1, 'price' => 25]],
        ]);
        $params = Fixture::$requests[0][1][0];
        same('opaque-attempt-fixture', $params['metadata']['pga_attempt_key'], 'Session metadata');
        same('opaque-attempt-fixture', $params['payment_intent_data']['metadata']['pga_attempt_key'], 'Deferred PI metadata');
        same(false, strpos(json_encode($params), 'S-INTERNAL-SECRET'), 'S identifier not transmitted');
        same(null, $result['transaction_id'], 'Deferred PI is valid');
        same(8, \Stripe\ApiRequestor::httpClient()->getTimeout(), 'API timeout bounded');
    };
    $tests['legacy PI lookup filters Session API and verifies returned PI association'] = function () {
        Fixture::reset((object) ['data' => [(object) ['id' => 'cs_fixture', 'payment_intent' => 'pi_fixture']]]);
        same('cs_fixture', gateway()->findCheckoutSessionForPaymentIntent('pi_fixture'), 'Recovered legacy Session');
        same(['sessions.all', ['payment_intent' => 'pi_fixture', 'limit' => 1]], Fixture::$requests[0], 'Server filtering');
        Fixture::reset((object) ['data' => []]);
        same(null, gateway()->findCheckoutSessionForPaymentIntent('pi_fixture'), 'Unrelated PI not matched');
        Fixture::reset((object) ['data' => [(object) ['id' => 'cs_fixture', 'payment_intent' => 'pi_other']]]);
        mustThrow(function () { gateway()->findCheckoutSessionForPaymentIntent('pi_fixture'); });
    };
    $tests['legacy creation retries keep old parameters while versioned attempts keep metadata stable'] = function () {
        foreach ([null, 0, 1] as $version) {
            Fixture::reset((object) ['id' => 'cs_fixture', 'url' => 'https://example.invalid/stripe',
                'payment_intent' => null, 'created' => 100, 'expires_at' => 200]);
            $transaction = [
                's_order_id' => 'S-INTERNAL', 'currency' => 'USD', 'amount' => '25.00',
                'shipping_fee' => 0, 'tax_fee' => 0, 'discount_fee' => 0, 'customer_email' => 'buyer@example.invalid',
                'invoice_number' => 'INV-001', 'payment_attempt_key' => 'opaque-persisted-key',
                'items' => [['name' => 'Item', 'sku' => 'SKU', 'quantity' => 1, 'price' => 25]],
            ];
            if ($version !== null) { $transaction['stripe_metadata_version'] = $version; }
            gateway()->preparePayment($transaction);
            gateway()->preparePayment($transaction);
            same(Fixture::$requests[0], Fixture::$requests[1], 'Same key has exactly the same parameters on retry');
            $params = Fixture::$requests[0][1][0];
            same($version === 1, isset($params['metadata']['pga_attempt_key']), 'Metadata gated by persisted version');
            if ($version !== 1) {
                same(['site_domain' => 'example.invalid', 'invoice_no' => 'INV-001'], $params['metadata'], 'Legacy Session metadata unchanged');
                same(false, array_key_exists('payment_intent_data', $params), 'Legacy PI parameters unchanged');
            }
        }
    };
    $tests['API failure preserves retryability for legacy PI lookup'] = function () {
        Fixture::reset(new \RuntimeException('Synthetic timeout'));
        mustThrow(function () { gateway()->findCheckoutSessionForPaymentIntent('pi_fixture'); });
    };
    $tests['fresh dispute status prevents stale creation from reopening resolved disputes'] = function () {
        foreach (['won' => 'dispute_won', 'warning_closed' => 'dispute_won', 'lost' => 'dispute_lost',
            'under_review' => 'dispute_created'] as $remote => $expected) {
            Fixture::reset((object) ['id' => 'du_fixture', 'payment_intent' => 'pi_fixture', 'amount' => 2500,
                'currency' => 'usd', 'status' => $remote]);
            $event = gateway()->refreshDisputeEvent(disputeEvent());
            same($expected, $event['event_status'], 'Current dispute outcome');
            same($remote, $event['dispute_status'], 'Remote status available for audit');
            same(['disputes.retrieve', 'du_fixture'], Fixture::$requests[0], 'Dispute read');
        }
    };
    $tests['dispute lookup failures and identity mismatches cannot authorize state changes'] = function () {
        foreach ([new \RuntimeException('Synthetic timeout'),
            (object) ['id' => 'du_other', 'status' => 'won'],
            (object) ['id' => 'du_fixture', 'payment_intent' => 'pi_other', 'status' => 'won'],
            (object) ['id' => 'du_fixture', 'payment_intent' => 'pi_fixture', 'status' => 'future_unknown']] as $response) {
            Fixture::reset($response);
            mustThrow(function () { gateway()->refreshDisputeEvent(disputeEvent()); });
        }
    };
    $tests['real SDK signature errors report safe diagnostics without retaining payload secrets'] = function () {
        $secret = 'whsec_synthetic-fixture';
        $payload = body('payment_intent.succeeded', ['id' => 'pi_fixture', 'client_secret' => 'private-payload-value']);
        $headers = ['stripe-signature' => \Stripe\WebhookSignature::generateSignatureHeader($payload, $secret)];
        Fixture::reset();
        same(true, gateway()->verifyCallbackSignature($payload, $headers, ['webhook_secret' => $secret]), 'Valid signature');
        same(false, gateway()->verifyCallbackSignature($payload . ' ', $headers, ['webhook_secret' => $secret]), 'Changed bytes rejected');
        $log = json_encode(Fixture::$logs);
        same(false, strpos($log, $secret), 'No webhook secret');
        same(false, strpos($log, 'private-payload-value'), 'No raw payload');
        same(false, strpos($log, $headers['stripe-signature']), 'No raw signature');
        same(true, strpos($log, 'signature_invalid') !== false, 'Reason is retained');
        Fixture::reset();
        $headers['stripe-signature'] = \Stripe\WebhookSignature::generateSignatureHeader($payload, $secret, time() - 600);
        same(false, gateway()->verifyCallbackSignature($payload, $headers, ['webhook_secret' => $secret]), 'Stale signature rejected');
        same(true, strpos(json_encode(Fixture::$logs), 'timestamp_outside_tolerance') !== false, 'Clock reason retained');
        Fixture::reset();
        same(false, gateway()->verifyCallbackSignature($payload, $headers, ['webhook_secret' => 'wrong-candidate'], false), 'Candidate probe still rejects invalid signature');
        same([], Fixture::$logs, 'Trying candidate credentials does not create warning noise');
    };

    $failures = 0;
    foreach ($tests as $name => $test) {
        try { $test(); echo 'PASS ' . $name . PHP_EOL; }
        catch (\Throwable $error) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
    }
    echo sprintf('%d tests, %d failures', count($tests), $failures) . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}

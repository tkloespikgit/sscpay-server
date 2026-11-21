<?php
/** Run: php tests/gateway-session-state.php. Real gateways with isolated SDK/HTTP/WordPress helpers. */

namespace {
    if (PHP_SAPI !== 'cli') {
        exit(1);
    }
    define('ABSPATH', dirname(__DIR__) . '/');
    function home_url($path = '') { return 'https://example.invalid' . $path; }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function wp_json_encode($value) { return json_encode($value); }
    function get_transient($key) { return 'synthetic-access-token'; }
    function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
    function add_query_arg($key, $value, $url = null)
    {
        $params = is_array($key) ? $key : [$key => $value];
        $url = is_array($key) ? $value : $url;
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }
}

namespace PaymentGatewayAggregator\Tests\GatewaySession {
    class Fixture
    {
        public static $http = [];
        public static $requests = [];
        public static $stripe;
        public static $expiration;
        public static $sessionCreates = [];
        public static $couponCreates = [];
        public static $retireCalls = [];

        public static function reset()
        {
            self::$http = self::$requests = self::$sessionCreates = self::$couponCreates = self::$retireCalls = [];
            self::$stripe = [
                'id' => 'cs_synthetic', 'url' => 'https://example.invalid/checkout',
                'status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => 'pi_synthetic',
                'amount_total' => 1234, 'currency' => 'usd', 'created' => 100, 'expires_at' => 200,
            ];
            self::$expiration = ['status' => 'expired', 'payment_status' => 'unpaid'];
        }
        public static function response($body, $status = 200)
        {
            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
        }
        public static function request($method, $url, array $body, array $headers)
        {
            self::$requests[] = compact('method', 'url', 'body', 'headers');
            if (!self::$http) {
                throw new \RuntimeException('Unexpected transport call in isolated gateway test');
            }
            $result = array_shift(self::$http);
            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;
        }
        public static function transaction()
        {
            return [
                's_order_id' => 'S-SYNTHETIC', 'payment_intent' => 'ORDER-SYNTHETIC',
                'pay_token' => 'synthetic-token', 'currency' => 'USD', 'amount' => '12.34',
                'customer_email' => 'buyer@example.invalid', 'customer_name' => 'Synthetic Buyer',
                'shipping_fee' => 0, 'tax_fee' => 0, 'discount_fee' => 1,
                'items' => [['name' => 'Synthetic item', 'sku' => 'SKU', 'price' => 13.34, 'quantity' => 1]],
                'invoice_number' => 'INVOICE-SYNTHETIC', 'payment_attempt_key' => 'attempt-synthetic-001',
                'stripe_metadata_version' => 1,
            ];
        }
        public static function config()
        {
            return ['mode' => 'sandbox', 'client_id' => 'synthetic-client', 'secret_key' => 'synthetic-key'];
        }
    }
    class StripeSessions
    {
        public function retrieve($id)
        {
            if (Fixture::$stripe instanceof \Throwable) {
                throw Fixture::$stripe;
            }
            return (object) Fixture::$stripe;
        }
        public function create(array $params, array $options = [])
        {
            Fixture::$sessionCreates[] = compact('params', 'options');
            return (object) Fixture::$stripe;
        }
        public function expire($id, array $params = [])
        {
            Fixture::$retireCalls[] = [$id, $params];
            if (Fixture::$expiration instanceof \Throwable) {
                throw Fixture::$expiration;
            }
            return (object) Fixture::$expiration;
        }
    }
    class StripeCoupons
    {
        public function create(array $params, array $options = [])
        {
            Fixture::$couponCreates[] = compact('params', 'options');
            return (object) ['id' => 'coupon-synthetic'];
        }
    }
}

namespace Stripe {
    class StripeClient
    {
        public $checkout;
        public $coupons;
        public function __construct($key)
        {
            $this->checkout = (object) ['sessions' => new \PaymentGatewayAggregator\Tests\GatewaySession\StripeSessions()];
            $this->coupons = new \PaymentGatewayAggregator\Tests\GatewaySession\StripeCoupons();
        }
    }
}

namespace PaymentGatewayAggregator\Helpers {
    class HttpClient
    {
        const DEFAULT_TIMEOUT = 30;
        public static function getJson($url, array $headers = [], $timeout = null, $proxy = null)
        {
            return \PaymentGatewayAggregator\Tests\GatewaySession\Fixture::request('GET', $url, [], $headers);
        }
        public static function postJson($url, array $body, array $headers = [], $timeout = null, $proxy = null)
        {
            return \PaymentGatewayAggregator\Tests\GatewaySession\Fixture::request('POST', $url, $body, $headers);
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    class RouteIdentity
    {
        public static function tokenParam() { return 'synthetic_token'; }
        public static function returnPath() { return 'return'; }
        public static function cancelPath() { return 'cancel'; }
        public static function restUrl($path) { return 'https://example.invalid/wp-json/synthetic/' . $path; }
    }
    class LogManager
    {
        public static function __callStatic($name, $arguments) {}
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
    use PaymentGatewayAggregator\Core\Exceptions\PayPalOrderNotFoundException;
    use PaymentGatewayAggregator\Gateways\PayPalJsGateway;
    use PaymentGatewayAggregator\Gateways\PayPalRestClient;
    use PaymentGatewayAggregator\Gateways\PayPalRestGateway;
    use PaymentGatewayAggregator\Gateways\StripeGateway;
    use PaymentGatewayAggregator\Tests\GatewaySession\Fixture;

    require dirname(__DIR__) . '/vendor/autoload.php';
    set_error_handler(function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
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
        throw new \RuntimeException('Expected a query/retirement error');
    }
    function missingPayPalOrder()
    {
        return ['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']],
            'message' => 'The specified resource does not exist.', 'debug_id' => 'synthetic-debug-id'];
    }
    function paypalOrder($status, $captureStatus = null)
    {
        $order = ['id' => 'ORDER-SYNTHETIC', 'status' => $status];
        if ($captureStatus !== null) {
            $order['purchase_units'] = [['payments' => ['captures' => [[
                'id' => 'CAPTURE-SYNTHETIC', 'status' => $captureStatus,
                'amount' => ['value' => '12.34', 'currency_code' => 'USD'],
            ]]]]];
        }
        return $order;
    }

    $tests = [];
    $tests['Stripe classifies open closed processing and unknown without failing unpaid orders'] = function () {
        foreach ([['open', 'reusable'], ['expired', 'closed'], ['complete', 'processing'], ['future-state', 'unknown']] as $case) {
            Fixture::reset();
            Fixture::$stripe['status'] = $case[0];
            $result = (new StripeGateway(Fixture::config()))->inspectPaymentSession(Fixture::transaction());
            same($case[1], $result['session_state'], 'Session state');
            same(null, $result['status'], 'Unpaid root order stays open');
        }
    };
    $tests['Stripe confirmed payment retains amount currency and PaymentIntent ID'] = function () {
        Fixture::reset();
        Fixture::$stripe['status'] = 'complete';
        Fixture::$stripe['payment_status'] = 'paid';
        $result = (new StripeGateway(Fixture::config()))->inspectPaymentSession(Fixture::transaction());
        same('paid', $result['status'], 'Confirmed paid');
        same(1234, $result['amount_minor'], 'Amount for validation');
        same('USD', $result['currency'], 'Currency for validation');
        same('pi_synthetic', $result['transaction_id'], 'PaymentIntent retained');
    };
    $tests['Stripe retirement actually expires the old open session and requires confirmation'] = function () {
        Fixture::reset();
        $gateway = new StripeGateway(Fixture::config());
        same(true, $gateway->retirePaymentSession(Fixture::transaction()), 'Expiry confirmed');
        same([['ORDER-SYNTHETIC', []]], Fixture::$retireCalls, 'Expire endpoint invoked');
        Fixture::$expiration = ['status' => 'open', 'payment_status' => 'unpaid'];
        same(false, $gateway->retirePaymentSession(Fixture::transaction()), 'Unconfirmed expiry cannot allow renewal');
    };
    $tests['Stripe never retires paid or complete-processing sessions'] = function () {
        foreach ([['complete', 'paid'], ['complete', 'unpaid'], ['open', 'no_payment_required']] as $case) {
            Fixture::reset();
            Fixture::$stripe['status'] = $case[0];
            Fixture::$stripe['payment_status'] = $case[1];
            same(false, (new StripeGateway(Fixture::config()))->retirePaymentSession(Fixture::transaction()), 'Existing payment protected');
            same([], Fixture::$retireCalls, 'No expiry request');
        }
    };
    $tests['Stripe already-expired unpaid sessions are confirmed without another expire call'] = function () {
        Fixture::reset();
        Fixture::$stripe['status'] = 'expired';
        same(true, (new StripeGateway(Fixture::config()))->retirePaymentSession(Fixture::transaction()), 'Already closed');
        same([], Fixture::$retireCalls, 'No duplicate expiry');
    };
    $tests['Stripe query and retirement network failures cannot authorize renewal'] = function () {
        Fixture::reset();
        $gateway = new StripeGateway(Fixture::config());
        Fixture::$stripe = new \RuntimeException('Synthetic GET timeout');
        mustThrow(function () use ($gateway) { $gateway->inspectPaymentSession(Fixture::transaction()); });
        mustThrow(function () use ($gateway) { $gateway->retirePaymentSession(Fixture::transaction()); });
        Fixture::reset();
        Fixture::$expiration = new \RuntimeException('Synthetic expire conflict or timeout');
        mustThrow(function () use ($gateway) { $gateway->retirePaymentSession(Fixture::transaction()); });
    };
    $tests['PayPal approval resumes the same Order while pending capture remains processing'] = function () {
        foreach ([PayPalJsGateway::class, PayPalRestGateway::class] as $class) {
            foreach ([['CREATED', null, 'reusable'], ['APPROVED', null, 'requires_approval'], ['PAYER_ACTION_REQUIRED', null, 'requires_approval'], ['COMPLETED', 'PENDING', 'processing']] as $case) {
                Fixture::reset();
                Fixture::$http[] = Fixture::response(paypalOrder($case[0], $case[1]));
                $result = (new $class(Fixture::config()))->inspectPaymentSession(Fixture::transaction());
                same($case[2], $result['session_state'], 'PayPal session classification');
                same(null, $result['status'], 'No payment claimed prematurely');
                same('GET', Fixture::$requests[0]['method'], 'Inspection never captures');
            }
        }
    };
    $tests['PayPal payment records outside the first capture cannot authorize another approval'] = function () {
        foreach (['APPROVED', 'PAYER_ACTION_REQUIRED', 'CREATED', 'VOIDED'] as $status) {
            foreach (['captures', 'authorizations'] as $type) {
                foreach ([0, 1] as $index) {
                    if ($type === 'captures' && $index === 0) { continue; }
                    Fixture::reset();
                    $order = paypalOrder($status);
                    $order['purchase_units'] = [['payments' => []]];
                    $order['purchase_units'][$index] = ['payments' => [$type => [['id' => 'OTHER-PAYMENT', 'status' => 'PENDING']]]];
                    Fixture::$http[] = Fixture::response($order);
                    $result = (new PayPalRestGateway(Fixture::config()))->inspectPaymentSession(Fixture::transaction());
                    same('unknown', $result['session_state'], 'Payment outside supported capture stays blocked');
                    same(null, $result['status'], 'No unsupported payment is treated as paid or failed');
                }
            }
        }
    };
    $tests['PayPal inspection exposes only trusted buyer approval links'] = function () {
        foreach (['https://www.paypal.com/checkoutnow?token=ORDER-SYNTHETIC', 'https://evil.invalid/checkout'] as $url) {
            Fixture::reset();
            $order = paypalOrder('PAYER_ACTION_REQUIRED');
            $order['links'] = [['rel' => 'payer-action', 'href' => $url]];
            Fixture::$http[] = Fixture::response($order);
            $result = (new PayPalRestGateway(Fixture::config()))->inspectPaymentSession(Fixture::transaction());
            same(strpos($url, 'https://www.paypal.com/') === 0 ? $url : null, $result['approval_url'], 'Approval redirect validated');
        }
    };
    $tests['PayPal needs a completed Capture and never treats top-level COMPLETED alone as paid'] = function () {
        foreach ([[null, null, 'unknown'], ['PENDING', null, 'processing'], ['COMPLETED', 'paid', 'unknown'], ['DECLINED', 'failed', 'unknown']] as $case) {
            Fixture::reset();
            Fixture::$http[] = Fixture::response(paypalOrder('COMPLETED', $case[0]));
            $result = (new PayPalRestGateway(Fixture::config()))->inspectPaymentSession(Fixture::transaction());
            same($case[1], $result['status'], 'Capture controls payment outcome');
            same($case[2], $result['session_state'], 'Pending/unknown controls renewal');
        }
    };
    $tests['PayPal VOIDED is closed but cannot override a completed or pending Capture'] = function () {
        foreach ([[null, null, 'closed'], ['COMPLETED', 'paid', 'unknown'], ['PENDING', null, 'processing']] as $case) {
            Fixture::reset();
            Fixture::$http[] = Fixture::response(paypalOrder('VOIDED', $case[0]));
            $result = (new PayPalRestGateway(Fixture::config()))->inspectPaymentSession(Fixture::transaction());
            same($case[1], $result['status'], 'Capture evidence has priority');
            same($case[2], $result['session_state'], 'Only unpaid closed order is renewable');
        }
    };
    $tests['PayPal missing Order classification requires HTTP 404 and both exact provider error codes'] = function () {
        $missing = missingPayPalOrder();
        $cases = [
            [404, $missing, true],
            [404, array_merge($missing, ['details' => [['issue' => 'OTHER_ISSUE'], ['issue' => 'INVALID_RESOURCE_ID']]]), true],
            [401, $missing, false], [403, $missing, false], [500, $missing, false],
            [404, [], false], [404, null, false], [404, '<html>Not Found</html>', false],
            [404, ['details' => [['issue' => 'INVALID_RESOURCE_ID']]], false],
            [404, array_merge($missing, ['name' => 'OTHER_ERROR']), false],
            [404, ['name' => 'RESOURCE_NOT_FOUND'], false],
            [404, array_merge($missing, ['details' => 'INVALID_RESOURCE_ID']), false],
            [404, array_merge($missing, ['details' => [null, 'INVALID_RESOURCE_ID']]), false],
            [404, array_merge($missing, ['details' => [['issue' => 'OTHER_ISSUE']]]), false],
        ];
        foreach ($cases as [$status, $body, $expectedMissing]) {
            Fixture::reset();
            Fixture::$http[] = Fixture::response($body, $status);
            $error = mustThrow(function () { PayPalRestClient::getOrder(Fixture::config(), 'ORDER-SYNTHETIC'); });
            same($expectedMissing, $error instanceof PayPalOrderNotFoundException, 'Typed error for HTTP ' . $status . ' ' . json_encode($body));
            same(1, substr_count($error->getMessage(), 'PayPal查询订单状态失败：'), 'Client error has one descriptive prefix');
        }
    };
    $tests['PayPal JS and REST propagate missing Order for inspection and ordinary query without duplicate prefixes'] = function () {
        foreach ([PayPalJsGateway::class, PayPalRestGateway::class] as $class) {
            foreach (['inspectPaymentSession', 'queryOrderStatus'] as $method) {
                Fixture::reset();
                Fixture::$http[] = Fixture::response(missingPayPalOrder(), 404);
                $error = mustThrow(function () use ($class, $method) { (new $class(Fixture::config()))->$method(Fixture::transaction()); });
                same(true, $error instanceof PayPalOrderNotFoundException, 'Missing resource remains an error outside session renewal');
                same(1, substr_count($error->getMessage(), 'PayPal查询订单状态失败：'), 'Query prefix appears once');
                same(true, strpos($error->getMessage(), 'synthetic-debug-id') !== false, 'Provider diagnostics retained');
                same(1, count(Fixture::$requests), 'One read-only request');
                same('GET', Fixture::$requests[0]['method'], 'Missing Order does not trigger capture');
            }
        }
    };
    $tests['PayPal ordinary 404 authentication server failures and timeouts never imply closure or expiry'] = function () {
        foreach ([PayPalJsGateway::class, PayPalRestGateway::class] as $class) {
            foreach ([Fixture::response([], 404), Fixture::response([], 401), Fixture::response([], 403),
                Fixture::response([], 500), ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Synthetic transport timeout'],
                new \RuntimeException('Synthetic timeout')] as $response) {
                Fixture::reset();
                Fixture::$http[] = $response;
                $error = mustThrow(function () use ($class) { (new $class(Fixture::config()))->inspectPaymentSession(Fixture::transaction()); });
                same(false, $error instanceof PayPalOrderNotFoundException, 'Other query failures cannot authorize renewal');
                if (is_array($response)) {
                    same(1, substr_count($error->getMessage(), 'PayPal查询订单状态失败：'), 'Ordinary query error prefix appears once');
                    if (!empty($response['error'])) {
                        same(true, strpos($error->getMessage(), $response['error']) !== false, 'Transport error diagnostics retained');
                    }
                }
            }
        }
    };
    $tests['PayPal does not pretend it can retire an old platform Order'] = function () {
        Fixture::reset();
        same(false, (new PayPalRestGateway(Fixture::config()))->retirePaymentSession(Fixture::transaction()), 'No retirement capability');
        same([], Fixture::$requests, 'No fake capture used as expiry probe');
    };
    $tests['Stripe session and coupon retries reuse distinct persisted attempt keys'] = function () {
        Fixture::reset();
        $gateway = new StripeGateway(Fixture::config());
        $session = $gateway->preparePayment(Fixture::transaction());
        $gateway->preparePayment(Fixture::transaction());
        same('pga-session-attempt-synthetic-001', Fixture::$sessionCreates[0]['options']['idempotency_key'], 'Stable session key');
        same(Fixture::$sessionCreates[0]['options'], Fixture::$sessionCreates[1]['options'], 'Session retry uses same key');
        same(Fixture::$couponCreates[0]['options'], Fixture::$couponCreates[1]['options'], 'Coupon retry uses same key');
        same(false, Fixture::$sessionCreates[0]['options'] === Fixture::$couponCreates[0]['options'], 'Coupon has independent key');
        same(false, array_key_exists('expires_at', Fixture::$sessionCreates[0]['params']), 'Local 23-hour policy does not override provider lifetime');
        same(100, $session['provider_created_at'], 'Actual provider creation time retained');
        same(200, $session['provider_expires_at'], 'Actual provider expiry retained');
        $next = Fixture::transaction();
        $next['payment_attempt_key'] = 'attempt-synthetic-002';
        $gateway->preparePayment($next);
        same('pga-session-attempt-synthetic-002', Fixture::$sessionCreates[2]['options']['idempotency_key'], 'New attempt uses new key');
    };
    $tests['PayPal JS and REST creation use the same persisted request key on retries'] = function () {
        foreach ([PayPalJsGateway::class, PayPalRestGateway::class] as $class) {
            Fixture::reset();
            $body = ['id' => 'ORDER-SYNTHETIC', 'links' => [['rel' => 'approve', 'href' => 'https://example.invalid/approve']]];
            Fixture::$http = [Fixture::response($body), Fixture::response($body), Fixture::response($body)];
            $gateway = new $class(Fixture::config());
            $gateway->preparePayment(Fixture::transaction());
            $gateway->preparePayment(Fixture::transaction());
            same('attempt-synthetic-001', Fixture::$requests[0]['headers']['PayPal-Request-Id'], 'Persisted request key');
            same(Fixture::$requests[0]['headers'], Fixture::$requests[1]['headers'], 'Retry keeps request key');
            same('CAPTURE', Fixture::$requests[0]['body']['intent'], 'Capture flow retained');
            $next = Fixture::transaction();
            $next['payment_attempt_key'] = 'attempt-synthetic-002';
            $gateway->preparePayment($next);
            same('attempt-synthetic-002', Fixture::$requests[2]['headers']['PayPal-Request-Id'], 'New attempt gets new request key');
        }
    };

    $failures = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo 'PASS ' . $name . PHP_EOL;
        } catch (\Throwable $error) {
            $failures++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
    echo sprintf('%d tests, %d failures', count($tests), $failures) . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}

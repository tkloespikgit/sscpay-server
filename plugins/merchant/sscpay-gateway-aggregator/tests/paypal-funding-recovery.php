<?php
/** Run: php tests/paypal-funding-recovery.php. Synthetic HTTP/WordPress fixtures; no live payment calls. */

namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    define('ABSPATH', dirname(__DIR__) . '/');
    function wp_json_encode($value) { return json_encode($value); }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function get_transient($key) { return 'synthetic-access-token'; }
    function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
    function __($message, $domain = null) { return $message; }
    function esc_html__($message, $domain = null) { return htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); }
    function esc_html_e($message, $domain = null) { echo esc_html__($message, $domain); }
    function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
    function add_query_arg($key, $value, $url = null)
    {
        $params = is_array($key) ? $key : [$key => $value];
        $url = is_array($key) ? $value : $url;
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }
    class WP_REST_Request
    {
        private $body;
        public function __construct(array $body) { $this->body = $body; }
        public function get_json_params() { return $this->body; }
        public function get_param($key) { return $this->body[$key] ?? null; }
    }
    class WP_REST_Response
    {
        private $data;
        private $status;
        public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}

namespace PaymentGatewayAggregator\Tests\FundingRecovery {
    class Fixture
    {
        public static $transaction;
        public static $http = [];
        public static $requests = [];
        public static $logs = [];
        public static $saves = 0;
        public static $saveRace = null;

        public static function reset()
        {
            self::$http = self::$requests = self::$logs = [];
            self::$saves = 0;
            self::$saveRace = null;
            self::$transaction = [
                's_order_id' => 'S-SYNTHETIC', 'payment_method' => 'paypal_js', 'status' => 'pending',
                'payment_intent' => 'ORDER-SYNTHETIC', 'pay_token' => 'synthetic-pay-token',
                'transaction_id' => null, 'paid_at' => null, 'currency' => 'USD', 'amount' => '12.34',
                'gateway_session_expires_at' => '2026-01-01 00:00:00',
                'gateway_session' => json_encode([
                    'external_ref' => 'ORDER-SYNTHETIC', 'order_id' => 'ORDER-SYNTHETIC',
                    'client_id' => 'synthetic-client', 'redirect_url' => 'https://www.paypal.com/checkoutnow?token=ORDER-SYNTHETIC',
                    'local_expires_at' => 1767225600, '_attempt_key' => 'synthetic-attempt-key',
                ]),
            ];
        }
        public static function config()
        {
            return ['mode' => 'live', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret'];
        }
        public static function declined($url = 'https://www.paypal.com/checkoutnow?token=ORDER-SYNTHETIC')
        {
            return [
                'name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']],
                'debug_id' => 'synthetic-debug-id', 'links' => [['rel' => 'redirect', 'href' => $url, 'method' => 'GET']],
            ];
        }
        public static function response($body, $status = 200)
        {
            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
        }
        public static function request($method, $url, array $body, array $headers)
        {
            self::$requests[] = compact('method', 'url', 'body', 'headers');
            if (!self::$http) { throw new \RuntimeException('Unexpected synthetic HTTP request'); }
            $response = array_shift(self::$http);
            if ($response instanceof \Throwable) { throw $response; }
            return $response;
        }
    }
}

namespace PaymentGatewayAggregator\Helpers {
    class HttpClient
    {
        const DEFAULT_TIMEOUT = 30;
        public static function postJson($url, array $body, array $headers = [], $timeout = null, $proxy = null)
        {
            return \PaymentGatewayAggregator\Tests\FundingRecovery\Fixture::request('POST', $url, $body, $headers);
        }
    }
}

namespace PaymentGatewayAggregator\Core {
    use PaymentGatewayAggregator\Tests\FundingRecovery\Fixture;
    class TransactionManager
    {
        public static function findBySOrderId($orderId)
        {
            return (Fixture::$transaction['s_order_id'] ?? null) === $orderId ? Fixture::$transaction : null;
        }
        public static function findByPayToken($token)
        {
            return (Fixture::$transaction['pay_token'] ?? null) === $token ? Fixture::$transaction : null;
        }
        public static function decryptGatewayConfig(array $transaction) { return Fixture::config(); }
        public static function getGatewaySession(array $transaction)
        {
            return json_decode($transaction['gateway_session'] ?? '', true) ?: [];
        }
        public static function savePendingSession(array $expected, array $session, array $extra = []): bool
        {
            Fixture::$saves++;
            if (Fixture::$saveRace === 'before-paid') { Fixture::$transaction['status'] = 'paid'; }
            if (Fixture::$transaction['status'] !== 'pending'
                || Fixture::$transaction['payment_intent'] !== $expected['payment_intent']
                || Fixture::$transaction['gateway_session'] !== $expected['gateway_session']) { return false; }
            // MySQL may report no affected rows for an identical cached session.
            if (Fixture::$transaction['gateway_session'] === json_encode($session) && $extra === []) { return false; }
            Fixture::$transaction = array_merge(Fixture::$transaction, $extra, ['gateway_session' => json_encode($session)]);
            if (Fixture::$saveRace === 'after-paid') { Fixture::$transaction['status'] = 'paid'; }
            return true;
        }
    }
    class PaymentSessionReferences
    {
        public static function withOrderLock($orderId, callable $callback) { return $callback(); }
    }
    class LogManager
    {
        public static function __callStatic($name, $args) { Fixture::$logs[] = [$name, $args]; }
    }
    class RouteIdentity
    {
        public static function tokenParam() { return 'synthetic_token'; }
        public static function capturePath() { return 'capture'; }
        public static function cancelPath() { return 'cancel'; }
        public static function restUrl($path) { return 'https://example.invalid/wp-json/synthetic/' . $path; }
        public static function readToken(\WP_REST_Request $request)
        {
            return (string) ($request->get_param(self::tokenParam()) ?? '');
        }
    }
}

namespace PaymentGatewayAggregator\Frontend {
    class PaymentPage
    {
        const CANCEL_REASON_CAPTURE_FAILED = 'capture_failed';
        public static function resultPageUrl(array $transaction) { return 'https://example.invalid/result'; }
        public static function cancelPageUrl(array $transaction, $reason = '')
        {
            return 'https://example.invalid/cancel' . ($reason !== '' ? '?reason=' . $reason : '');
        }
    }
}

namespace {
    use PaymentGatewayAggregator\API\Controllers\PayPalJsController;
    use PaymentGatewayAggregator\API\Controllers\PayPalRestController;
    use PaymentGatewayAggregator\Core\Exceptions\PayPalCaptureException;
    use PaymentGatewayAggregator\Core\PayPalPaymentRecovery;
    use PaymentGatewayAggregator\Core\TransactionManager;
    use PaymentGatewayAggregator\Gateways\PayPalJsGateway;
    use PaymentGatewayAggregator\Gateways\PayPalRestClient;
    use PaymentGatewayAggregator\Tests\FundingRecovery\Fixture;

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
    function captureRequest($overrides = [])
    {
        return new WP_REST_Request(array_merge([
            's_order_id' => 'S-SYNTHETIC', 'token' => 'synthetic-pay-token', 'order_id' => 'ORDER-SYNTHETIC',
        ], $overrides));
    }
    function renderedButtonsScript()
    {
        $gateway = new PayPalJsGateway(Fixture::config());
        $html = $gateway->renderPaymentPage(Fixture::$transaction, TransactionManager::getGatewaySession(Fixture::$transaction));
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $html, $matches);
        return implode("\n", $matches[1]);
    }
    function restCaptureResult()
    {
        $method = new \ReflectionMethod(PayPalRestController::class, 'captureApprovedOrder');
        $method->setAccessible(true);
        $request = new WP_REST_Request([
            'synthetic_token' => 'synthetic-pay-token', 'token' => 'ORDER-SYNTHETIC',
        ]);
        return $method->invoke(null, $request, Fixture::$transaction);
    }
    function runBrowserChecks($script)
    {
        $runner = <<<'JS'
const vm = require('node:vm');
let text = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', x => text += x);
process.stdin.on('end', async () => {
  const source = JSON.parse(text);
  const cases = [
    ['declined', {code:0,data:{captured:false,issue:'INSTRUMENT_DECLINED',restart:true}}, 1, ''],
    ['success', {code:0,data:{captured:true,status:'COMPLETED'}}, 0, 'https://example.invalid/result'],
    ['uncertain', {code:0,data:{captured:false}}, 0, 'https://example.invalid/result'],
    ['business-error', {code:10005,data:{captured:false,issue:'INSTRUMENT_DECLINED',restart:true}}, 0, null],
    ['network-error', null, 0, null],
    ['no-actions', {code:0,data:{captured:false,issue:'INSTRUMENT_DECLINED',restart:true,redirect_url:'https://www.paypal.com/checkoutnow?token=ORDER-SYNTHETIC'}}, 0, 'https://www.paypal.com/checkoutnow?token=ORDER-SYNTHETIC'],
  ];
  for (const [name, payload, expectedRestart, expectedUrl] of cases) {
    let options, restarts=0, requests=0;
    const elements = {};
    const sandbox = {
      document:{getElementById:id => elements[id] ||= {style:{},textContent:''}},
      window:{location:{href:''}},
      paypal:{Buttons:opts => { options=opts; return {render:() => Promise.resolve()}; }},
      fetch:async () => { requests++; if(payload===null) throw new Error('synthetic-network-error'); return {ok:true,json:async()=>payload}; },
    };
    vm.runInNewContext(source, sandbox);
    await options.onApprove({orderID:'ORDER-SYNTHETIC'}, name==='no-actions' ? undefined : {restart:async()=>{restarts++;}});
    if (restarts !== expectedRestart) throw new Error(name+': restart count '+restarts);
    if (requests !== 1) throw new Error(name+': capture request count '+requests);
    if (expectedUrl !== null && sandbox.window.location.href !== expectedUrl) throw new Error(name+': unexpected URL '+sandbox.window.location.href);
    if (name==='business-error' && sandbox.window.location.href==='https://example.invalid/result') throw new Error('Business error incorrectly treated as successful capture');
    if (name==='network-error' && !sandbox.window.location.href.includes('reason=capture_failed')) throw new Error('Network failure did not reach a retry page');
  }
  process.stdout.write('PASS rendered PayPal buttons recovery and uncertain outcomes\n');
}).on('error', err => { console.error(err); process.exitCode=1; });
JS;
        $pipes = [];
        $nodeBinary = getenv('NODE_BINARY') ?: 'node';
        $process = proc_open([$nodeBinary, '-e', $runner], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Node is required to execute the rendered PayPal JS recovery tests'); }
        fwrite($pipes[0], json_encode($script));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) { throw new \RuntimeException('Rendered PayPal JS test failed: ' . $stdout . $stderr); }
        echo $stdout;
    }

    $tests = [];
    $tests['capture preserves PayPal issue, debug ID and a valid recovery URL'] = function () {
        Fixture::reset();
        Fixture::$http[] = Fixture::response(Fixture::declined(), 422);
        try {
            PayPalRestClient::captureOrder(Fixture::config(), 'ORDER-SYNTHETIC');
            throw new \RuntimeException('Expected a typed capture failure');
        } catch (PayPalCaptureException $error) {
            same(true, $error->isInstrumentDeclined(), 'Recoverable funding decline');
            same('synthetic-debug-id', $error->debugId(), 'Debug ID retained');
            same('https://www.paypal.com/checkoutnow?token=ORDER-SYNTHETIC', $error->recoveryUrl(), 'PayPal recovery URL retained');
        }
        same(1, count(Fixture::$requests), 'No blind server-side capture retry');
    };
    $tests['recovery redirects reject spoofed or unsafe PayPal URLs'] = function () {
        foreach ([
            'https://www.paypal.com.attacker.invalid/checkoutnow', 'https://attacker.invalid/checkoutnow',
            'http://www.paypal.com/checkoutnow', '//www.paypal.com/checkoutnow',
            'https://buyer@www.paypal.com/checkoutnow', 'https://www.paypal.com:444/checkoutnow',
            "https://www.paypal.com/checkoutnow\r\nLocation: https://attacker.invalid/",
        ] as $url) {
            same(null, (new PayPalCaptureException(Fixture::declined($url)))->recoveryUrl(), 'Unsafe redirect rejected');
        }
        $sandbox = 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-SYNTHETIC';
        same($sandbox, (new PayPalCaptureException(Fixture::declined($sandbox)))->recoveryUrl(), 'Sandbox supported');
    };
    $tests['other PayPal failures are not classified as recoverable funding declines'] = function () {
        $body = Fixture::declined();
        $body['details'][0]['issue'] = 'TRANSACTION_REFUSED';
        same(false, (new PayPalCaptureException($body))->isInstrumentDeclined(), 'Other decline remains distinct');
    };
    $tests['successful capture returns the actual provider result'] = function () {
        Fixture::reset();
        $result = ['id' => 'ORDER-SYNTHETIC', 'status' => 'COMPLETED'];
        Fixture::$http[] = Fixture::response($result, 201);
        same($result, PayPalRestClient::captureOrder(Fixture::config(), 'ORDER-SYNTHETIC'), 'Successful response retained');
        same(0, Fixture::$saves, 'Client does not mark a local order paid');
    };
    $tests['funding recovery retains the same order, attempt and expiration'] = function () {
        Fixture::reset();
        $before = Fixture::$transaction;
        $transaction = PayPalPaymentRecovery::remember($before, new PayPalCaptureException(Fixture::declined()));
        $session = TransactionManager::getGatewaySession($transaction);
        same('INSTRUMENT_DECLINED', $session['_paypal_funding_recovery']['issue'], 'Recovery issue persisted');
        same('ORDER-SYNTHETIC', $session['_paypal_funding_recovery']['order_id'], 'Recovery scoped to original Order');
        same('ORDER-SYNTHETIC', $transaction['payment_intent'], 'PayPal Order not replaced');
        same('synthetic-attempt-key', $session['_attempt_key'], 'Attempt retained');
        same($before['gateway_session_expires_at'], $transaction['gateway_session_expires_at'], 'Link/session expiry not extended');
        same('pending', $transaction['status'], 'No false failed/paid transition');
    };
    $tests['JS capture tells the buyer to restart instead of claiming successful capture'] = function () {
        Fixture::reset();
        Fixture::$http[] = Fixture::response(Fixture::declined(), 422);
        $response = PayPalJsController::capture(captureRequest());
        $body = $response->get_data();
        same(0, $body['code'], 'Structured capture outcome');
        same(false, $body['data']['captured'], 'Capture did not succeed');
        same('INSTRUMENT_DECLINED', $body['data']['issue'], 'Funding failure propagated');
        same(true, $body['data']['restart'], 'Buyer can select another funding source');
        same('pending', Fixture::$transaction['status'], 'Local order remains payable');
    };
    $tests['repeated funding declines restart the same Order without rewriting an identical marker'] = function () {
        Fixture::reset();
        for ($i = 0; $i < 2; $i++) {
            Fixture::$http[] = Fixture::response(Fixture::declined(), 422);
            $body = PayPalJsController::capture(captureRequest())->get_data();
            same(0, $body['code'], 'Repeated decline remains a recoverable result');
            same(true, $body['data']['restart'], 'Buyer can choose another funding source after each decline');
        }
        same(1, Fixture::$saves, 'Identical marker is not rewritten or dependent on affected row count');
        same(2, count(Fixture::$requests), 'Exactly one requested capture for each buyer approval');
        foreach (Fixture::$requests as $request) {
            same('https://api-m.paypal.com/v2/checkout/orders/ORDER-SYNTHETIC/capture', $request['url'], 'No new PayPal Order created');
        }
        same('ORDER-SYNTHETIC', Fixture::$transaction['payment_intent'], 'Same PayPal Order retained');
    };
    $tests['REST capture resumes through a trusted PayPal recovery URL or local cancel fallback'] = function () {
        foreach (['provider-url', 'cached-url', 'cancel-fallback'] as $case) {
            Fixture::reset();
            Fixture::$transaction['payment_method'] = 'paypal_rest';
            $providerUrl = $case === 'provider-url'
                ? 'https://www.paypal.com/checkoutnow?token=ORDER-SYNTHETIC&retry=1'
                : 'https://attacker.invalid/checkoutnow';
            if ($case === 'cancel-fallback') {
                $session = TransactionManager::getGatewaySession(Fixture::$transaction);
                $session['redirect_url'] = 'https://www.paypal.com.attacker.invalid/checkoutnow';
                Fixture::$transaction['gateway_session'] = json_encode($session);
            }
            Fixture::$http[] = Fixture::response(Fixture::declined($providerUrl), 422);
            $result = restCaptureResult();
            $expected = $case === 'provider-url' ? $providerUrl : ($case === 'cached-url'
                ? 'https://www.paypal.com/checkoutnow?token=ORDER-SYNTHETIC'
                : 'https://example.invalid/cancel?reason=capture_failed');
            same($expected, $result['_paypal_capture_retry_url'], 'Safe REST recovery destination');
            same('pending', Fixture::$transaction['status'], 'REST recovery leaves the order payable');
            same(1, count(Fixture::$requests), 'No immediate automatic second capture');
        }
        Fixture::reset();
        Fixture::$transaction['payment_method'] = 'paypal_rest';
        Fixture::$http[] = new \RuntimeException('Synthetic capture timeout');
        $result = restCaptureResult();
        same(false, isset($result['_paypal_capture_retry_url']), 'Uncertain capture goes to result verification');
        same(0, Fixture::$saves, 'Transport failure does not manufacture a funding recovery marker');
    };
    $tests['recovery markers cannot overwrite a changed or nonpayable session'] = function () {
        foreach (['paid', 'creating', 'wrong-reference', 'wrong-order-id'] as $case) {
            Fixture::reset();
            $session = TransactionManager::getGatewaySession(Fixture::$transaction);
            if ($case === 'paid') { Fixture::$transaction['status'] = 'paid'; }
            if ($case === 'creating') { $session['_creating'] = true; }
            if ($case === 'wrong-reference') { $session['external_ref'] = 'OTHER-ORDER'; }
            if ($case === 'wrong-order-id') { $session['order_id'] = 'OTHER-ORDER'; }
            Fixture::$transaction['gateway_session'] = json_encode($session);
            try {
                PayPalPaymentRecovery::remember(Fixture::$transaction, new PayPalCaptureException(Fixture::declined()));
                throw new \LogicException('Expected rejection of ' . $case);
            } catch (\RuntimeException $error) {
                same(0, Fixture::$saves, 'Changed/nonpayable session remains untouched');
            }
        }
    };
    $tests['concurrent payment confirmation suppresses funding recovery'] = function () {
        foreach (['before-paid', 'after-paid'] as $race) {
            Fixture::reset();
            Fixture::$saveRace = $race;
            Fixture::$http[] = Fixture::response(Fixture::declined(), 422);
            $body = PayPalJsController::capture(captureRequest())->get_data();
            same(false, $body['data']['restart'] ?? false, 'No restart after concurrent payment confirmation');
            same('paid', Fixture::$transaction['status'], 'Confirmed paid status retained');
        }
    };
    $tests['capture token and current-session checks still prevent stale or unauthorized capture'] = function () {
        foreach ([['token' => 'wrong-token'], ['order_id' => 'OLD-ORDER-SYNTHETIC']] as $overrides) {
            Fixture::reset();
            $response = PayPalJsController::capture(captureRequest($overrides));
            same(true, $response->get_data()['code'] !== 0, 'Unauthorized/stale capture refused');
            same([], Fixture::$requests, 'No PayPal capture request');
            same(0, Fixture::$saves, 'No recovery state written');
        }
        Fixture::reset();
        Fixture::$transaction['status'] = 'paid';
        same(true, PayPalJsController::capture(captureRequest())->get_data()['code'] !== 0, 'Paid order not captured again');
        same([], Fixture::$requests, 'No second capture');
    };
    $tests['uncertain transport failures do not become funding-restart instructions'] = function () {
        Fixture::reset();
        Fixture::$http[] = new \RuntimeException('Synthetic capture timeout');
        $body = PayPalJsController::capture(captureRequest())->get_data();
        same(false, $body['data']['captured'], 'Unknown capture outcome');
        same(false, $body['data']['restart'] ?? false, 'No automatic second payment');
        same(0, Fixture::$saves, 'No funding recovery marker from a timeout');
    };
    $tests['rendered JS checks the capture outcome and restarts only recoverable declines'] = function () {
        Fixture::reset();
        runBrowserChecks(renderedButtonsScript());
    };

    foreach ($tests as $name => $test) {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    }
    echo count($tests) . ' PayPal funding recovery tests passed.' . PHP_EOL;
}

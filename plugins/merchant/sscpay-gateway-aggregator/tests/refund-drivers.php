<?php
/** Run: php tests/refund-drivers.php. HTTP and Stripe listing calls are isolated. */

use PaymentGatewayAggregator\Gateways\AntomGateway;
use PaymentGatewayAggregator\Gateways\RefundDriver;
use PaymentGatewayAggregator\Gateways\RefundEvidence;
use PaymentGatewayAggregator\Gateways\StripeGateway;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ABSPATH', dirname(__DIR__) . '/');
spl_autoload_register(static function (string $name): void {
    $prefix = 'PaymentGatewayAggregator\\';
    if (strpos($name, $prefix) === 0) {
        require ABSPATH . 'src/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
    }
});
function get_transient($key) { return 'test-token'; }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($response) { return false; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
function wp_remote_retrieve_response_code($response) { return 200; }
function wp_remote_retrieve_body($response) { return $response['raw']; }
function wp_remote_get($url, $options) { return ($GLOBALS['http'])($url, $options); }
function wp_remote_post($url, $options) { return ($GLOBALS['http'])($url, $options); }
function checkSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected ' . json_encode($expected) . ' got ' . json_encode($actual));
    }
}
function rejected(callable $action, string $message): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        if (strpos($error->getMessage(), $message) !== false) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected an error containing ' . $message);
}
function readEvent(string $method, array $event, ?array $tx = [], array $config = []): array
{
    return RefundDriver::read($method, json_encode($event), [], $config, $tx);
}
final class ListStripeRefunds extends StripeGateway
{
    public $pages;
    public $options = [];

    protected function refundClient()
    {
        return (object) ['refunds' => $this];
    }

    public function all(array $options, array $requestOptions)
    {
        $this->options[] = $options;
        if (!$this->pages) {
            throw new RuntimeException('Unexpected Stripe refund page');
        }
        return new class(array_shift($this->pages)) {
            private $page;
            public function __construct(array $page) { $this->page = $page; }
            public function toArray(): array { return $this->page; }
        };
    }
}
$time = '2025-06-01T12:00:00Z';
$object = ['object' => 'refund', 'id' => 're_A', 'charge' => 'ch_A', 'payment_intent' => 'pi_A', 'amount' => 499, 'currency' => 'usd', 'status' => 'succeeded', 'balance_transaction' => ['created' => 1748779200]];
$event = ['id' => 'evt_1', 'type' => 'refund.created', 'created' => 1748779200, 'livemode' => false, 'data' => ['object' => $object]];
checkSame(['pi_A', 'ch_A'], RefundDriver::references('stripe', json_encode($event)), 'Stripe refund is never a payment reference');
$fact = readEvent('stripe', $event)['refunds'][0];
checkSame(['re_A', '4.99', 'USD', $time, false], [$fact['refund_id'], $fact['amount'], $fact['currency'], $fact['refunded_at'], $fact['livemode']], 'Stripe principal and verified event time');
foreach (['pending', 'requires_action', 'failed', 'canceled'] as $status) {
    $event['data']['object']['status'] = $status;
    $event['type'] = $status === 'failed' ? 'refund.failed' : 'refund.updated';
    checkSame([], readEvent('stripe', $event)['refunds'], 'Unsuccessful refund');
}
$charge = ['type' => 'charge.refunded', 'created' => 1748779200, 'data' => ['object' => ['id' => 'ch_A', 'payment_intent' => 'pi_A', 'amount_refunded' => 99999, 'refunded' => false]]];
$stripe = new ListStripeRefunds();
$stripe->pages = [
    ['data' => [$object], 'has_more' => true],
    ['data' => [array_merge($object, ['id' => 're_B'])], 'has_more' => false],
];
$listed = $stripe->readRefund($charge, []);
checkSame(['re_A', 're_B'], array_column($listed['refunds'], 'refund_id'), 'Both equal partials survive pagination');
checkSame(['4.99', '4.99'], array_column($listed['refunds'], 'amount'), 'Ignore Charge cumulative amount');
checkSame('ch_A', $stripe->options[0]['charge'], 'First Stripe query must be payment scoped');
checkSame('re_A', $stripe->options[1]['starting_after'], 'Stripe pagination cursor');
$undated = $object;
unset($undated['balance_transaction']);
$stripe->pages = [['data' => [$object, array_merge($undated, ['id' => 're_C'])], 'has_more' => false]];
$dated = $stripe->readRefund($charge, []);
checkSame(['re_A'], array_column($dated['refunds'], 'refund_id'), 'An undated refund cannot contaminate verified siblings');
checkSame(null, $dated['evidence']['observations'][0]['refunded_at'], 'A cumulative Charge time cannot date an older individual refund');
$stripe->pages = [['data' => [array_merge($object, ['balance_transaction' => ['created' => 1748779200]])], 'has_more' => false]];
$verified = $stripe->queryRefunds(['transaction_id' => 'foreign_local_ref', 'payment_intent' => 'pi_A']);
checkSame(['pi_A', 'ch_A'], $verified['refunds'][0]['payment_refs'], 'Local unverified refs must not enter provider evidence');
checkSame($time, $verified['refunds'][0]['refunded_at'], 'An expanded Stripe balance transaction supplies its own time');
$stripe->pages = [['data' => [array_merge($object, ['charge' => 'ch_X'])], 'has_more' => false]];
rejected(static function () use ($stripe, $charge) { $stripe->readRefund($charge, []); }, '所查的原付款');
checkSame(null, RefundEvidence::principal('101', 'BHD', 'stripe'), 'Do not round fractional cents');
checkSame('1.23', RefundEvidence::principal('1230', 'BHD', 'stripe'), 'Three decimal minor amounts');
checkSame('100.00', RefundEvidence::principal('100', 'JPY', 'stripe'), 'Zero decimal currency');

$paypal = ['id' => 'WH-123', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource_type' => 'refund', 'create_time' => $time, 'resource' => [
    'id' => 'REF1', 'status' => 'COMPLETED', 'amount' => ['value' => '1.37', 'currency_code' => 'USD'], 'update_time' => $time,
    'seller_payable_breakdown' => ['total_refunded_amount' => ['value' => '5.00', 'currency_code' => 'USD']],
    'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER1']],
    'links' => [['rel' => 'up', 'href' => 'https://api-m.paypal.com/v2/payments/captures/CAP1']],
]];
$config = ['mode' => 'sandbox', 'client_id' => 'synthetic-client'];
foreach (['paypal', 'paypal_js', 'paypal_rest'] as $method) {
    checkSame(['CAP1', 'ORDER1'], RefundDriver::references($method, json_encode($paypal)), 'Refund resource parent links ' . $method);
    checkSame('1.37', readEvent($method, $paypal)['refunds'][0]['amount'], 'Individual PayPal principal');
}
$typeHint = $paypal;
$typeHint['resource_type'] = 'capture';
$typeHint['resource']['links'][] = ['rel' => 'self', 'href' => 'https://api-m.paypal.com/v2/payments/refunds/REF1'];
checkSame('REF1', readEvent('paypal', $typeHint)['refunds'][0]['refund_id'], 'Use the PSP resource identity when the envelope has a broad capture type');
$base = 'https://api-m.sandbox.paypal.com/v2/';
$GLOBALS['http'] = static function ($url) use ($paypal, $base) {
    checkSame($base . 'checkout/orders/ORDER1', $url, 'Only query known local PayPal order');
    $unit = ['payments' => ['captures' => [['id' => 'CAP1', 'status' => 'PARTIALLY_REFUNDED']], 'refunds' => [$paypal['resource']]]];
    $second = ['payments' => ['captures' => [['id' => 'CAP2', 'status' => 'REFUNDED']], 'refunds' => [
        array_merge($paypal['resource'], ['id' => 'REF2', 'links' => [['rel' => 'up', 'href' => $base . 'payments/captures/CAP2']]]),
    ]]];
    return ['raw' => json_encode(['id' => 'ORDER1', 'purchase_units' => [$unit, $second]])];
};
$captureEvent = array_merge($paypal, ['resource_type' => 'capture', 'resource' => ['id' => 'CAP1', 'status' => 'PARTIALLY_REFUNDED', 'amount' => ['value' => '999.99', 'currency_code' => 'USD']]]);
$listed = readEvent('paypal_js', $captureEvent, ['payment_intent' => 'ORDER1'], $config);
checkSame(['REF1', 'REF2'], array_column($listed['refunds'], 'refund_id'), 'List all purchase units without using capture amount');
$multiCapture = RefundDriver::query('paypal_js', $config, ['payment_intent' => 'ORDER1', 'transaction_id' => 'CAP2']);
checkSame(['REF2'], array_column($multiCapture['refunds'], 'refund_id'), 'Only book refunds of the winning capture');
checkSame('CAP1', $multiCapture['evidence']['observations'][0]['payment_refs'][1], 'Different capture is held with real origin');
checkSame('lookup', readEvent('paypal', $paypal, ['transaction_id' => 'CAP2'])['kind'], 'Order ID cannot override a different capture');
$noParents = $paypal;
unset($noParents['resource']['links'], $noParents['resource']['supplementary_data'], $noParents['resource']['status']);
$GLOBALS['http'] = static function ($url) use ($base, $paypal) {
    checkSame($base . 'payments/refunds/REF1', $url, 'Payment-scoped PayPal refund retrieval');
    return ['raw' => json_encode($paypal['resource'])];
};
checkSame(['CAP1', 'ORDER1'], readEvent('paypal_rest', $noParents, [], $config)['refunds'][0]['payment_refs'], 'Recover true original from Refund API');

$air = '{"name":"refund.settled","data":{"object":{"id":"rfd_A","payment_intent_id":"int_A","payment_attempt_id":"att_A","status":"SETTLED","currency":"USD","amount":9999999999999.99,"updated_at":"2025-06-01T12:00:00+0000"}}}';
$fact = RefundDriver::read('airwallex', $air, [], [], null)['refunds'][0];
checkSame('9999999999999.99', $fact['amount'], 'JSON numeric amount retains exact representation');
checkSame('0.01', RefundDriver::read('airwallex', str_replace('9999999999999.99', '1E-2', $air), [], [], null)['refunds'][0]['amount'], 'Scientific JSON numbers retain their exact decimal value');
checkSame(['int_A', 'att_A'], $fact['payment_refs'], 'Airwallex refund ID never matched as a payment');
checkSame([], RefundDriver::read('airwallex', str_replace(['settled','SETTLED'], ['accepted','ACCEPTED'], $air), [], [], null)['refunds'], 'Accepted awaits settlement');
checkSame([], RefundDriver::read('airwallex', str_replace('SETTLED', 'FAILED', $air), [], [], null)['refunds'], 'Conflicting Airwallex states');
$pages = 0;
$GLOBALS['http'] = static function ($url) use (&$pages, $air) {
    parse_str(parse_url($url, PHP_URL_QUERY), $params);
    checkSame(['payment_intent_id' => 'int_A', 'page_num' => (string) $pages, 'page_size' => '100'], $params, 'Scoped Airwallex page');
    $refund = RefundEvidence::decode($air)['data']['object'];
    $refund['id'] .= (string) $pages;
    return ['raw' => json_encode(['items' => [$refund], 'has_more' => $pages++ === 0])];
};
checkSame(['rfd_A0', 'rfd_A1'], array_column(RefundDriver::query('airwallex', $config, ['payment_intent' => 'int_A'])['refunds'], 'refund_id'), 'Airwallex list follows has_more');

$antom = ['notifyType' => 'REFUND_RESULT', 'refundId' => 'REF1', 'refundRequestId' => 'REQ1', 'refundStatus' => 'SUCCESS',
    'refundAmount' => ['value' => '152', 'currency' => 'USD'], 'refundTime' => $time, 'result' => ['resultStatus' => 'S']];
checkSame([], RefundDriver::references('antom', json_encode($antom)), 'An orphan has no original payment');
$orphan = readEvent('antom', $antom, null);
checkSame(['lookup', [], 'REF1'], [$orphan['kind'], $orphan['refunds'], $orphan['evidence']['refund_id']], 'Keep orphan for exact matching');
checkSame('failed', readEvent('antom', array_merge($antom, ['refundId' => null, 'refundStatus' => 'FAIL']), null)['kind'], 'No lookup for an explicitly failed orphan');
checkSame([], readEvent('antom', array_merge($antom, ['paymentId' => 'PAY1', 'refundStatus' => 'FAIL']))['refunds'], 'Successful inquiry result cannot override failed refund status');
checkSame('lookup', readEvent('antom', array_merge($antom, ['paymentId' => 'PAY1', 'result' => ['resultStatus' => 'F']]))['kind'], 'Conflicting notification results require an inquiry');
$key = openssl_pkey_new(['private_key_bits' => 2048]);
openssl_pkey_export($key, $private);
$config += ['region_domain' => 'example.invalid', 'private_key' => $private, 'antom_public_key' => openssl_pkey_get_details($key)['key']];
$original = ['transaction_id' => 'PAY1', 'gateway_session' => json_encode(['payment_request_id' => 'ORD1'])];
$corrupt = false;
$GLOBALS['http'] = static function ($url, $options) use ($antom, $key, $time, $config, &$corrupt) {
    $path = parse_url($url, PHP_URL_PATH);
    $query = json_decode($options['body'], true);
    $header = $options['headers'];
    $message = "POST {$path}\n{$header['Client-Id']}.{$header['Request-Time']}.{$options['body']}";
    $signature = rawurldecode(substr($header['Signature'], strpos($header['Signature'], 'signature=') + strlen('signature=')));
    checkSame(1, openssl_verify($message, base64_decode($signature), $config['antom_public_key'], OPENSSL_ALGO_SHA256), 'Antom signs full actual URI and body');
    if ($path === '/ams/sandbox/api/v1/payments/inquiryPayment') {
        checkSame(['paymentId' => 'PAY1'], $query, 'Inquiry scoped to actual payment');
        $body = ['result' => ['resultStatus' => 'S'], 'paymentId' => 'PAY1', 'paymentRequestId' => 'ORD1', 'transactions' => [
            ['transactionType' => 'REFUND','transactionId' => 'REF1','transactionRequestId' => 'REQ1'],
            ['transactionType' => 'CAPTURE','transactionId' => 'CAP1'],
        ]];
    } else {
        checkSame('/ams/sandbox/api/v1/payments/inquiryRefund', $path, 'Antom only queries refunds');
        checkSame(['refundId' => 'REF1'], $query, 'Retrieve exact enumerated refund');
        $body = $antom;
    }
    $raw = json_encode($body);
    openssl_sign("POST {$path}\n{$header['Client-Id']}.{$time}.{$raw}", $sig, $key, OPENSSL_ALGO_SHA256);
    return ['raw' => $raw, 'headers' => ['client-id' => $header['Client-Id'], 'response-time' => $time, 'signature' => 'algorithm=RSA256, keyVersion=1, signature=' . rawurlencode(base64_encode($corrupt ? 'mismatch' : $sig))]];
};
$listed = RefundDriver::query('antom', $config, $original);
checkSame(['REF1', ['PAY1', 'ORD1'], '1.52'], [$listed['refunds'][0]['refund_id'], $listed['refunds'][0]['payment_refs'], $listed['refunds'][0]['amount']], 'Signed Antom child refund attaches to verified parent');
$corrupt = true;
rejected(static function () use ($config, $original) { RefundDriver::query('antom', $config, $original); }, '响应未通过验签');
$raw = json_encode($antom);
$path = '/wp-json/synthetic/antom';
openssl_sign("POST {$path}\nsynthetic-client.{$time}.{$raw}", $signature, $key, OPENSSL_ALGO_SHA256);
$headers = ['client-id' => 'synthetic-client', 'request-time' => $time, 'signature' => 'algorithm=RSA256, keyVersion=1, signature=' . rawurlencode(base64_encode($signature)), 'x-pga-http-path' => $path];
$gateway = new AntomGateway($config);
checkSame(true, $gateway->verifyCallbackSignature($raw, $headers, $config), 'Antom receipt signature');
checkSame(false, $gateway->verifyCallbackSignature($raw . ' ', $headers, $config), 'Antom rejects tampering');
echo "Refund driver fixtures passed.\n";

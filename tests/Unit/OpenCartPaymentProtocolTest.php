<?php

namespace Tests\Unit;

use App\Support\SignatureCanonicalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sscpay\OpenCart\Client;
use Sscpay\OpenCart\Decimal;
use Sscpay\OpenCart\Payload;
use Sscpay\OpenCart\PaymentState;
use Sscpay\OpenCart\Signature;
use Tests\Support\OpenCartRuntime;

require_once dirname(__DIR__).'/Support/OpenCartRuntime.php';

class OpenCartPaymentProtocolTest extends TestCase
{
    public function test_signature_preserves_nested_values_and_matches_the_server(): void
    {
        $body = ['items' => [['name' => '商品/蓝色', 'price' => '1.00']], 'empty' => [], 'nullable' => null, 'sign' => 'ignored'];
        $this->assertSame(SignatureCanonicalizer::canonicalize($body), Signature::canonical($body));
        $this->assertSame(SignatureCanonicalizer::sign('app', '1800000000', 'nonce-123', $body, 'secret'), Signature::sign('app', '1800000000', 'nonce-123', $body, 'secret'));
        $body['sign'] = Signature::sign('app', '1800000000', 'nonce-123', $body, 'secret');
        Signature::verify($body, ['App-ID' => 'app', 'Timestamp' => '1800000000', 'X-Nonce' => 'nonce-123'], 'app', 'secret', 1800000000);
        $body['items'][0]['price'] = '2.00';
        $this->expectException(RuntimeException::class);
        Signature::verify($body, ['App-ID' => 'app', 'Timestamp' => '1800000000', 'X-Nonce' => 'nonce-123'], 'app', 'secret', 1800000000);
    }

    public static function invalidHeaders(): array
    {
        return ['wrong app' => [['App-ID' => 'other']], 'expired' => [['Timestamp' => '1799999600']],
            'future' => [['Timestamp' => '1800000400']], 'empty nonce' => [['X-Nonce' => '']]];
    }

    #[DataProvider('invalidHeaders')]
    public function test_bad_notification_headers_are_rejected(array $replace): void
    {
        $body = ['amount' => '1.00'];
        $body['sign'] = Signature::sign('app', '1800000000', 'nonce-123', $body, 'secret');
        $headers = array_replace(['App-ID' => 'app', 'Timestamp' => '1800000000', 'X-Nonce' => 'nonce-123'], $replace);
        $this->expectException(RuntimeException::class);
        Signature::verify($body, $headers, 'app', 'secret', 1800000000);
    }

    public function test_currency_conversion_and_arithmetic_preserve_cents_without_float_rounding(): void
    {
        $this->assertSame('10.00', Decimal::convert('8.0000', '1.25000000'));
        $this->assertSame('0.01', Decimal::convert('0.0050', '1.00000000'));
        $this->assertSame('123456789012.35', Decimal::money('123456789012.345'));
        $this->assertSame('2000000000000000000000000', Decimal::multiply('1000000000000000000000000', '2'));
        $this->assertSame('999999999999999999999999', Decimal::subtract('1000000000000000000000000', '1'));
        $this->expectException(InvalidArgumentException::class);
        Decimal::cents(1.00);
    }

    public function test_payload_uses_the_order_currency_snapshot_and_reconciles_discounts_and_fees(): void
    {
        $order = array_replace(OpenCartRuntime::order(), ['total' => '21.0000', 'currency_value' => '1.25000000']);
        $products = [['product_id' => 7, 'model' => 'SKU', 'name' => 'Product', 'price' => '8.0000', 'quantity' => 2]];
        $totals = [['code' => 'shipping', 'value' => '2.0000'], ['code' => 'tax', 'value' => '2.0000']];
        $settings = ['platform' => 'opencart', 'group_key' => 'combine', 'payment_method_key' => ''];
        $urls = ['products' => [7 => 'https://shop.test/product/7'], 'store' => 'https://shop.test/', 'notify' => 'https://shop.test/notify', 'return' => 'https://shop.test/return', 'cancel' => 'https://shop.test/cancel'];
        $body = Payload::create($order, $products, $totals, $settings, $urls, 'OC3-0-42');
        $this->assertSame('26.25', $body['amount']);
        $this->assertSame('10.00', $body['items'][0]['unit_price']);
        $this->assertSame('21.25', $body['subtotal']);
        $this->assertSame('1.25', $body['items'][1]['unit_price']);
        $this->assertSame('0.00', $body['discount']);
        $this->assertArrayNotHasKey('payment_method_key', $body);
        $order['total'] = '19.0000';
        $body = Payload::create($order, $products, $totals, $settings, $urls, 'OC3-0-42');
        $this->assertSame('1.25', $body['discount']);
        $this->assertSame('20.00', $body['subtotal']);
        $this->assertSame('23.75', $body['amount']);
    }

    public function test_partial_payment_duplicates_and_out_of_order_events_never_complete_or_regress_an_order(): void
    {
        $record = OpenCartRuntime::record();
        $partial = OpenCartRuntime::event($record);
        $record['state'] = PaymentState::apply($record, $partial, 'notify');
        $this->assertSame('partial', $record['state']['stage']);
        $this->assertFalse($record['state']['paid_once']);
        $this->assertSame($record['state'], PaymentState::apply($record, $partial, 'notify'));
        $record['state'] = PaymentState::apply($record, OpenCartRuntime::event($record, '100.00', 2), 'notify');
        $this->assertSame('paid', $record['state']['stage']);
        $this->assertTrue($record['state']['paid_once']);
        $this->assertSame($record['state'], PaymentState::apply($record, $partial, 'query'));
        $review = OpenCartRuntime::event($record, '100.00', 2);
        $review['status'] = $review['order_status'] = 'review';
        $this->assertSame('review', PaymentState::apply($record, $review, 'query')['order_status']);
        $this->assertSame('paid', PaymentState::apply($record, $review, 'query')['stage']);
    }

    public static function invalidProgress(): array
    {
        return ['merchant' => [['merchant_order_no' => 'OTHER']], 'system order' => [['order_no' => 'OTHER']],
            'currency' => [['currency' => 'EUR']], 'amount' => [['amount' => '99.00']], 'float amount' => [['amount' => 100.00]],
            'sum' => [['remaining_amount' => '59.99']], 'paid partial' => [['status' => 'paid']],
            'missing event' => [['event_id' => '']], 'version string' => [['version' => '1']],
            'children missing' => [['children' => []]], 'negative paid' => [['paid_amount' => '-1.00']]];
    }

    #[DataProvider('invalidProgress')]
    public function test_inconsistent_protocol_data_is_rejected(array $changes): void
    {
        $record = OpenCartRuntime::record();
        $body = array_replace(OpenCartRuntime::event($record), $changes);
        $this->expectException(\Throwable::class);
        PaymentState::apply($record, $body, 'notify');
    }

    public function test_single_v1_v2_and_aftersales_queries_keep_paid_orders_from_being_restored(): void
    {
        $record = OpenCartRuntime::record();
        $event = OpenCartRuntime::event($record, '100.00');
        $event['order_type'] = 'single';
        $event['children'] = [];
        $record['state'] = PaymentState::apply($record, $event, 'notify');
        $query = ['order_no' => $record['remote_order_no'], 'merchant_order_no' => $record['merchant_order_no'], 'currency' => 'USD', 'amount' => '100.00', 'status' => 'refunded'];
        $record['state'] = PaymentState::apply($record, $query, 'query');
        $this->assertSame('refunded', $record['state']['stage']);
        $query['status'] = 'paid';
        $this->assertSame('refunded', PaymentState::apply($record, $query, 'query')['stage']);
        $this->assertSame('refunded', PaymentState::apply($record, $event, 'notify')['stage']);
        $record['state'] = [];
        $this->assertSame('paid', PaymentState::apply($record, $query, 'notify')['stage']);
    }

    public function test_shipping_requires_full_payment_and_uses_paid_child_references(): void
    {
        $record = OpenCartRuntime::record();
        $record['state'] = PaymentState::apply($record, OpenCartRuntime::event($record), 'query');
        try {
            PaymentState::shippingPayloads($record, []);
            $this->fail('Partial payment was shipped.');
        } catch (RuntimeException) {
            $this->assertFalse($record['state']['paid_once']);
        }
        $record['state'] = PaymentState::apply($record, OpenCartRuntime::event($record, '100.00', 2), 'query');
        $payloads = PaymentState::shippingPayloads($record, ['tracking_number' => 'TRACK']);
        $this->assertSame(['OC3-0-42-S001'], array_column($payloads, 'merchant_order_no'));
        $record['state']['order_status'] = 'review';
        $this->expectException(RuntimeException::class);
        PaymentState::shippingPayloads($record, ['tracking_number' => 'TRACK']);
    }

    public function test_client_signs_original_types_and_never_puts_secrets_into_urls_or_errors(): void
    {
        $sent = [];
        $client = new Client('https://api.test', 'app', 'secret', function ($url, $headers, $json) use (&$sent) {
            $sent = [$url, $headers, json_decode($json, true)];

            return ['status' => 200, 'body' => '{"code":0,"data":{"order_no":"REMOTE"}}'];
        });
        $this->assertSame(['order_no' => 'REMOTE'], $client->request('query', ['amount' => '1.00']));
        $this->assertSame('https://api.test/api/order/query', $sent[0]);
        $this->assertSame('1.00', $sent[2]['amount']);
        $headers = [];
        foreach ($sent[1] as $header) {
            [$key, $value] = explode(': ', $header, 2);
            $headers[$key] = $value;
        }
        $this->assertSame(SignatureCanonicalizer::sign('app', $headers['Timestamp'], $headers['X-Nonce'], $sent[2], 'secret'), $sent[2]['sign']);
        $client = new Client('https://api.test', 'app', 'secret', fn () => ['status' => 422, 'body' => '{"code":422,"msg":"secret"}']);
        $this->expectExceptionMessage('SSCPay request failed (HTTP 422, code 422).');
        $client->request('create', []);
    }

    public function test_client_rejects_insecure_or_authenticated_base_urls(): void
    {
        $this->expectException(RuntimeException::class);
        new Client('https://app:secret@api.test', 'app', 'secret');
    }
}

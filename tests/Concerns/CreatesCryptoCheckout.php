<?php

namespace Tests\Concerns;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoCheckout;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\CryptoConfigurationTest;

trait CreatesCryptoCheckout
{
    use CreatesCryptoHistory;

    protected function checkoutFixture(bool $withPayment = false, array $requestOverrides = []): array
    {
        [$monitor, $rpc] = $this->historyFixture(requestOverrides: $requestOverrides);
        config(['app.url' => 'http://localhost', 'services.tatum.checkout_enabled' => true,
            'services.tatum.evm_min_confirmations.ethereum-sepolia' => '3']);
        $this->withoutVite();
        SystemConfig::set('crypto.quote_ttl_seconds', '900');
        SystemConfig::set('crypto.max_market_price_age_seconds', '60');
        SystemConfig::set('crypto.quote_markup_percent', '2');
        SystemConfig::set('crypto.subscription_reopen_max_renewals', '3');
        PaymentMethod::query()->findOrFail($monitor->paymentRequest->payment_method_id)->update([
            'config' => CryptoConfigurationTest::publicConfig(), 'fee_percent' => '1', 'fee_fixed' => '0',
        ]);
        $checkout = CryptoCheckout::factory()->create([
            'merchant_id' => $monitor->merchant_id, 'order_id' => $monitor->paymentRequest->order_id,
            'payment_method_id' => $monitor->paymentRequest->payment_method_id, 'current_request_id' => $monitor->crypto_payment_request_id,
            'requested_at' => now()->subMinute(),
        ]);
        if (! $withPayment) {
            $rpc['blocks'][100]['transactions'] = [];
            $rpc['receipts'] = $rpc['traces'] = $rpc['logs'] = [];
        }

        return [$checkout, $monitor, $rpc];
    }

    protected function fakeCheckoutProvider(array &$rpc): void
    {
        $rpc['alerts'] ??= [];
        $rpc['provider_handler'] = function (Request $request) use (&$rpc) {
            if (str_contains($request->url(), '/v3/ethereum/address/')) {
                preg_match('~/([0-9]+)(?:\?|$)~', $request->url(), $matches);
                $letter = ['a', 'b', 'c', 'e', 'f'][(int) $matches[1]];

                return Http::response(['address' => '0x'.str_repeat($letter, 40)]);
            }
            if (str_contains($request->url(), '/v4/data/rate/symbol')) {
                return Http::response(['value' => $rpc['price'] ?? '2500', 'timestamp' => now()->getTimestampMs(), 'basePair' => 'USD', 'symbol' => 'ETH']);
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v4/subscription') {
                if ($request->method() === 'PUT') {
                    return Http::response('', 204);
                }
                if ($request->method() === 'GET') {
                    return Http::response(array_values($rpc['alerts']));
                }
                $this->assertSame('POST', $request->method());
                $monitor = CryptoAddressMonitor::query()->where('callback_url', $request['attr']['url'])->sole();
                $id = 'alert-'.$monitor->id;
                $rpc['alerts'][$id] = $this->alertData($monitor, $id);
                if (isset($rpc['after_subscribe'])) {
                    $rpc['after_subscribe']($monitor);
                }

                return Http::response(['id' => $id], 201);
            }
            $id = basename($path);
            if ($request->method() === 'DELETE') {
                unset($rpc['alerts'][$id]);

                return Http::response('', 204);
            }
            $this->assertSame('GET', $request->method());
            $this->assertSame('/v4/subscription/'.$id, $path);

            return isset($rpc['alerts'][$id]) ? Http::response($rpc['alerts'][$id]) : Http::response([], 404);
        };
        $this->fakeHistoryRpc($rpc);
    }
}

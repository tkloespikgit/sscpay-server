<?php

namespace Tests\Concerns;

use App\Models\CryptoAddress;
use App\Models\CryptoAddressMonitor;
use App\Models\CryptoPaymentRequest;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\SystemConfig;
use App\Services\CryptoAddressMonitorService;
use App\Services\CryptoQuoteService;
use Illuminate\Support\Carbon;

trait CreatesCryptoMonitor
{
    use CreatesTestOrders;

    protected function createCryptoMonitor(array $requestOverrides = [], array $addressOverrides = []): CryptoAddressMonitor
    {
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00'));
        $this->createMerchantAndApplication();
        config([
            'services.tatum.subscriptions_enabled' => true,
            'services.tatum.history_enabled' => true,
            'services.tatum.webhook_base_url' => 'https://notify.example.test',
            'services.tatum.api_key' => 'test-mainnet-credential',
            'services.tatum.testnet_key' => 'test-testnet-credential',
            'services.tatum.mainnet_hmac_secret' => 'test-mainnet-webhook-secret-123456',
            'services.tatum.testnet_hmac_secret' => 'test-testnet-webhook-secret-123456',
        ]);
        SystemConfig::set('crypto.subscription_ttl_seconds', '60');
        SystemConfig::set('crypto.history_scan_interval_seconds', '60');
        $map = PaymentMethodConfigMap::create(['name' => 'Monitor ETH', 'payment_type' => 'crypto', 'fields' => [], 'is_active' => true]);
        $method = PaymentMethod::create([
            'merchant_id' => $this->merchant->id, 'method_code' => 'monitor-eth', 'method_name' => 'ETH',
            'payment_type' => 'crypto', 'config_map_id' => $map->id, 'is_active' => true,
        ]);
        $order = $this->makeOrder('monitor-order', 'monitor-order', ['payment_type' => 'crypto', 'payment_method_id' => $method->id]);
        $address = CryptoAddress::factory()->create([
            'merchant_id' => $this->merchant->id, 'order_id' => $order->id, 'payment_method_id' => $method->id, 'scan_from_block' => 100,
            ...$addressOverrides,
        ]);
        $quote = app(CryptoQuoteService::class)->calculate('ethereum-sepolia:ETH', '100', 'USD', '1', '2500', '2', '1', '0');
        $request = CryptoPaymentRequest::factory()->create([
            'merchant_id' => $this->merchant->id, 'order_id' => $order->id, 'payment_method_id' => $method->id,
            'crypto_address_id' => $address->id, 'quote_snapshot' => $quote,
            'due_units' => $quote['due_units'], 'fee_units' => $quote['fee_units'], 'net_units' => $quote['net_units'],
            ...$requestOverrides,
        ]);

        return app(CryptoAddressMonitorService::class)->prepare($this->merchant->id, $request->id, historyOnly: true);
    }

    protected function alertData(CryptoAddressMonitor $monitor, string $id = 'alert-1'): array
    {
        return [
            'id' => $id, 'type' => 'ADDRESS_EVENT', 'attr' => [
                'chain' => $monitor->network, 'address' => $monitor->address->address, 'url' => $monitor->callback_url,
            ],
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoCheckout;
use App\Models\PaymentMethod;
use chillerlan\QRCode\QRCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoMonitor;
use Tests\TestCase;

class CryptoCheckoutQrTest extends TestCase
{
    use CreatesCryptoMonitor;
    use RefreshDatabase;

    private const ETH_ADDRESS = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ETH_CONTRACT = '0xdAC17F958D2ee523a2206206994597C13D831ec7';

    private const TRON_ADDRESS = 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL';

    private const TRON_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    #[DataProvider('paymentUris')]
    public function test_ready_qr_encodes_the_exact_payment_amount_and_network(string $network, string $asset, int $decimals, string $units, string $address, string $expectedUri): void
    {
        [$monitor] = $this->readyCheckout($network, $asset, $decimals, $units, $address);
        Cache::put('crypto-address-qr:'.hash('sha256', $address), 'old-address-only-qr', 86400);
        PaymentMethod::query()->findOrFail($monitor->paymentRequest->payment_method_id)
            ->update(['config' => ['contract_address' => '0x'.str_repeat('b', 40)]]);

        $this->getJson($this->endpoint($monitor))->assertOk()->assertJsonPath('state', 'ready')
            ->assertJsonPath('address', $address)->assertJsonPath('qr_type', 'amount')
            ->assertJsonPath('payment_uri', $expectedUri)->assertJsonPath('qr', (new QRCode)->render($expectedUri))
            ->assertJsonMissingPath('tron_payment')->assertJsonMissingPath('quote_snapshot')
            ->assertDontSee('private-public-account-reference');

        Http::assertNothingSent();
    }

    public static function paymentUris(): array
    {
        $address = self::ETH_ADDRESS;
        $contract = self::ETH_CONTRACT;

        return [
            'BTC mainnet' => ['bitcoin-mainnet', 'BTC', 8, '12345678', 'bc1qexample', 'bitcoin:bc1qexample?amount=0.12345678'],
            'BTC smallest unit' => ['bitcoin-testnet', 'BTC', 8, '1', 'tb1qexample', 'bitcoin:tb1qexample?amount=0.00000001'],
            'LTC mainnet' => ['litecoin-mainnet', 'LTC', 8, '250000000', 'ltc1qexample', 'litecoin:ltc1qexample?amount=2.50000000'],
            'LTC testnet' => ['litecoin-testnet', 'LTC', 8, '1', 'tltc1qexample', 'litecoin:tltc1qexample?amount=0.00000001'],
            'ETH mainnet' => ['ethereum-mainnet', 'ETH', 18, '9007199254740993', $address, 'ethereum:'.$address.'@1?value=9007199254740993'],
            'ETH sepolia' => ['ethereum-sepolia', 'ETH', 18, '1', $address, 'ethereum:'.$address.'@11155111?value=1'],
            'ERC20 mainnet' => ['ethereum-mainnet', 'USDT', 6, '123456789', $address, 'ethereum:'.$contract.'@1/transfer?address='.$address.'&uint256=123456789'],
            'ERC20 testnet precision' => ['ethereum-sepolia', 'USDT', 6, '9007199254740993', $address, 'ethereum:'.$contract.'@11155111/transfer?address='.$address.'&uint256=9007199254740993'],
        ];
    }

    #[DataProvider('tronNetworks')]
    public function test_tron_displays_an_address_qr_and_exact_amount_without_a_wallet_confirmation_flow(string $network): void
    {
        [$monitor] = $this->readyCheckout($network, 'USDT', 6, '9007199254740993', self::TRON_ADDRESS);

        $this->getJson($this->endpoint($monitor))->assertOk()->assertJsonPath('state', 'ready')
            ->assertJsonPath('address', self::TRON_ADDRESS)
            ->assertJsonPath('qr_type', 'address')->assertJsonPath('payment_uri', self::TRON_ADDRESS)
            ->assertJsonPath('qr', (new QRCode)->render(self::TRON_ADDRESS))
            ->assertJsonPath('due_amount', '9007199254.740993')
            ->assertJsonMissingPath('tron_payment')
            ->assertJsonMissingPath('quote_snapshot')->assertDontSee('private-public-account-reference');
        $this->get('/payment/tok-monitor-order')->assertOk()
            ->assertSee('id="copy-amount"', false)->assertSee('id="copy-address"', false)
            ->assertDontSee('id="wallet-pay"', false)->assertDontSee('id="wallet-status"', false);

        Http::assertNothingSent();
    }

    public static function tronNetworks(): array
    {
        return ['mainnet' => ['tron-mainnet'], 'shasta' => ['tron-shasta']];
    }

    #[DataProvider('incompleteTokenSnapshots')]
    public function test_incomplete_token_snapshots_keep_an_address_qr_without_inventing_a_transfer(string $network, bool $withContract, bool $withGenesis): void
    {
        $address = str_starts_with($network, 'tron-') ? self::TRON_ADDRESS : self::ETH_ADDRESS;
        [$monitor] = $this->readyCheckout($network, 'USDT', 6, '123456789', $address, $withContract, $withGenesis);

        $this->getJson($this->endpoint($monitor))->assertOk()->assertJsonPath('qr_type', 'address')
            ->assertJsonPath('payment_uri', $address)->assertJsonPath('qr', (new QRCode)->render($address))
            ->assertJsonMissingPath('tron_payment');
    }

    public static function incompleteTokenSnapshots(): array
    {
        return [
            'ERC20 missing contract' => ['ethereum-sepolia', false, true],
            'TRON missing contract' => ['tron-mainnet', false, true],
            'TRON missing chain identity' => ['tron-shasta', true, false],
        ];
    }

    #[DataProvider('unpayableStates')]
    public function test_unpayable_orders_hide_the_address_and_payment_qr(string $state): void
    {
        [$monitor, $checkout] = $this->readyCheckout('tron-mainnet', 'USDT', 6, '123456789', self::TRON_ADDRESS);
        match ($state) {
            'checking' => $checkout->update(['status' => 'checking']),
            'review' => $monitor->paymentRequest->update(['review_reason' => 'manual_review']),
            'paid' => $monitor->paymentRequest->order->update(['status' => 'paid', 'paid_at' => now()]),
            'unavailable' => $this->merchant->update(['status' => false]),
            'expired' => $checkout->update(['status' => 'expired']),
        };
        if ($state === 'expired') {
            $this->travel(16)->minutes();
        }

        $this->getJson($this->endpoint($monitor))->assertOk()->assertJsonPath('state', $state)
            ->assertJsonPath('address', null)->assertJsonPath('qr', null)->assertJsonPath('qr_type', null)
            ->assertJsonPath('payment_uri', null)->assertJsonMissingPath('tron_payment');
    }

    public static function unpayableStates(): array
    {
        return ['checking' => ['checking'], 'expired' => ['expired'], 'review' => ['review'],
            'paid' => ['paid'], 'unavailable' => ['unavailable']];
    }

    private function readyCheckout(string $network, string $asset, int $decimals, string $units, string $address, bool $withContract = true, bool $withGenesis = true): array
    {
        $monitor = $this->createCryptoMonitor([
            'network' => $network, 'asset' => $asset, 'decimals' => $decimals, 'due_units' => $units, 'net_units' => $units, 'fee_units' => '0',
            'quote_snapshot' => ['payment_config_snapshot' => [
                'contract_address' => $withContract ? (str_starts_with($network, 'tron-') ? self::TRON_CONTRACT : self::ETH_CONTRACT) : null,
                'extended_public_key' => 'private-public-account-reference',
            ], 'verification_policy' => ['genesis_hash' => $withGenesis ? str_repeat('a', 64) : null]],
        ], ['network' => $network, 'address' => $address]);
        $this->setCryptoSettings(['app.url' => 'http://localhost', 'crypto.verification_enabled' => true,
            'crypto.settlement_enabled' => true, 'crypto.history_max_head_age_seconds' => '3600']);
        $this->withoutVite();
        $monitor->paymentRequest->update(['status' => 'ready', 'history_to_block' => 100, 'history_to_hash' => str_repeat('b', 64),
            'history_to_time' => now(), 'history_checked_at' => now()]);
        $monitor->update(['status' => 'active', 'expires_at' => now()->addMinutes(15)]);
        $checkout = CryptoCheckout::factory()->create([
            'merchant_id' => $monitor->merchant_id, 'order_id' => $monitor->paymentRequest->order_id,
            'payment_method_id' => $monitor->paymentRequest->payment_method_id, 'current_request_id' => $monitor->crypto_payment_request_id,
            'status' => 'ready', 'ready_at' => now(), 'handoff_at' => now(),
        ]);
        Http::preventStrayRequests();

        return [$monitor, $checkout];
    }

    private function endpoint(CryptoAddressMonitor $monitor): string
    {
        return 'http://localhost/payment/'.$monitor->paymentRequest->order->payment_link_token.'/crypto';
    }
}

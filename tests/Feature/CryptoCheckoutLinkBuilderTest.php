<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Services\Crypto\Chains\TronChainAdapter;
use App\Services\Crypto\CryptoCheckoutLinkBuilder;
use Tests\TestCase;

class CryptoCheckoutLinkBuilderTest extends TestCase
{
    public function test_tron_trc20_prefers_tronlink_deep_link_and_keeps_plain_address(): void
    {
        $merchant = new Merchant(['name' => 'Test Merchant']);
        $order = new Order([
            'order_no' => 'ORD-TEST-1',
            'payment_link_token' => 'PL_test_token_123456789012',
            'crypto_chain' => 'tron',
            'crypto_symbol' => 'USDT',
            'crypto_token_contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'crypto_receive_address' => 'TTestReceiveAddressCryptoPoC00001',
            'converted_amount' => '899.000',
        ]);
        $order->setRelation('merchant', $merchant);

        $result = app(CryptoCheckoutLinkBuilder::class)->forOrder(
            $order,
            'https://pay.example.test/payment/PL_test_token_123456789012',
        );

        $this->assertSame('TTestReceiveAddressCryptoPoC00001', $result['address']);
        $this->assertNotNull($result['deep_link']);
        $this->assertStringStartsWith('tronlinkoutside://pull.activity?param=', (string) $result['deep_link']);
        $this->assertSame('Open in TronLink', $result['wallet_label']);

        $query = parse_url((string) $result['deep_link'], PHP_URL_QUERY);
        $this->assertIsString($query);
        parse_str($query, $parts);
        $payload = json_decode(urldecode($parts['param'] ?? ''), true);

        $this->assertIsArray($payload);
        $this->assertSame('transfer', $payload['action']);
        $this->assertSame('TTestReceiveAddressCryptoPoC00001', $payload['to']);
        $this->assertSame('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', $payload['contract']);
        $this->assertSame('', $payload['tokenId']);
        $this->assertSame('899', $payload['amount']);
        $this->assertSame(TronChainAdapter::MAINNET_CHAIN_ID, $payload['chainId']);
    }

    public function test_unsupported_chain_falls_back_to_plain_address(): void
    {
        $order = new Order([
            'order_no' => 'ORD-TEST-3',
            'payment_link_token' => 'PL_test_token_unknown',
            'crypto_chain' => 'solana',
            'crypto_symbol' => 'USDT',
            'crypto_token_contract' => '',
            'crypto_receive_address' => 'So111example',
            'converted_amount' => '0.010',
        ]);

        $result = app(CryptoCheckoutLinkBuilder::class)->forOrder($order);

        $this->assertNull($result['deep_link']);
        $this->assertNull($result['wallet_label']);
        $this->assertSame('So111example', $result['address']);
    }
}

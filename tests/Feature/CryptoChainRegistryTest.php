<?php

namespace Tests\Feature;

use App\Services\Crypto\Chains\CryptoChainRegistry;
use App\Services\Crypto\Chains\TronChainAdapter;
use Tests\TestCase;

class CryptoChainRegistryTest extends TestCase
{
    public function test_resolves_and_normalizes_tron(): void
    {
        $registry = app(CryptoChainRegistry::class);

        $this->assertInstanceOf(TronChainAdapter::class, $registry->resolve('tron-mainnet'));
        $this->assertSame('tron', $registry->normalize('TRON'));
        $this->assertNull($registry->resolve('ethereum'));
        $this->assertNull($registry->resolve('bitcoin'));
        $this->assertNull($registry->resolve('solana'));
    }

    public function test_placeholder_addresses_are_chain_specific(): void
    {
        $registry = app(CryptoChainRegistry::class);

        $this->assertTrue($registry->isPlaceholderAddress('TReplaceMePlease'));
        $this->assertFalse($registry->isPlaceholderAddress('TTestReceiveAddressCryptoPoC00001'));
        // Ethereum 适配器未注册时，其占位串不由 Registry 识别
        $this->assertFalse($registry->isPlaceholderAddress('0xReplaceWithYourAddress'));
    }
}

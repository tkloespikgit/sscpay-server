<?php

namespace Tests\Unit;

use App\Support\CryptoPublicKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Feature\CryptoConfigurationTest;

class CryptoPublicKeyTest extends TestCase
{
    /** Same key material as CryptoConfigurationTest::XPUB, with Bitcoin testnet version bytes. */
    public const TPUB = 'tpubDFEqGFj9otGT7iZzeprYvEGpMyLm3X3owRJDRxrHAxp71QTV79T52VoNfuTE13PHrdn77jCpTJyo2bPkZmgmhuGe5Cq1NCodTrmkPh3D6yW';

    /** Tatum Litecoin testnet version 0436f6e1 of the same public key. */
    public const TTUB = 'ttub4gE5D65UTrrzcnqXgxurCiNPbdYYBuKncaumTzXuUphfk1ZGh8sNy2N28Ue6ZsBrGxQSU2FJ6etUWLwSpTg86ZGJYejj3LArNk7KgGnfC9j';

    public function test_litecoin_testnet_key_changes_only_the_version_prefix_and_checksum(): void
    {
        $converted = CryptoPublicKey::litecoinTestnetKey(self::TPUB);

        $this->assertSame(self::TTUB, $converted);
        $this->assertSame(111, strlen($converted));
        $this->assertNotSame(self::TPUB, $converted);
        $this->assertSame(
            bin2hex(substr(CryptoPublicKey::payload(self::TPUB), 4)),
            bin2hex(substr($this->payload($converted), 4)),
        );
    }

    public function test_litecoin_testnet_key_rejects_an_xpub(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Litecoin testnet derivation requires a tpub.');

        CryptoPublicKey::litecoinTestnetKey(CryptoConfigurationTest::XPUB);
    }

    /** Independent Base58Check decode so the fixture checksum is not trusted blindly. */
    private function payload(string $key): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $number = '0';
        foreach (str_split($key) as $character) {
            $number = bcadd(bcmul($number, '58', 0), (string) strpos($alphabet, $character), 0);
        }
        $decoded = '';
        while (bccomp($number, '0', 0) > 0) {
            $decoded = chr((int) bcmod($number, '256', 0)).$decoded;
            $number = bcdiv($number, '256', 0);
        }
        $payload = substr($decoded, 0, -4);
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        $this->assertSame($checksum, substr($decoded, -4));
        $this->assertSame('0436f6e1', bin2hex(substr($payload, 0, 4)));

        return $payload;
    }
}

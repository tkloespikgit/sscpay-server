<?php

namespace Tests\Feature;

use App\Services\CryptoQuoteService;
use App\Support\CryptoAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CryptoQuoteTest extends TestCase
{
    #[DataProvider('assets')]
    public function test_quotes_original_fiat_rate_and_credits_markup_to_merchant(string $asset, string $price, string $due, string $fee, string $net): void
    {
        $quote = app(CryptoQuoteService::class)->calculate($asset, '200.00', 'AUD', '0.50', $price, '2', '1', '0');

        $this->assertSame($due, $quote['due_units']);
        $this->assertSame($fee, $quote['fee_units']);
        $this->assertSame($net, $quote['net_units']);
        $this->assertSame('0.50', $quote['fiat_usd_rate']);
    }

    public static function assets(): array
    {
        return [
            'BTC' => ['bitcoin-mainnet:BTC', '100000', '102000', '1020', '100980'],
            'LTC' => ['litecoin-mainnet:LTC', '100', '102000000', '1020000', '100980000'],
            'ETH' => ['ethereum-mainnet:ETH', '2500', '40800000000000000', '408000000000000', '40392000000000000'],
            'ERC20' => ['ethereum-mainnet:USDT', '0.99', '103030304', '1030304', '102000000'],
            'TRC20' => ['tron-mainnet:USDT', '0.99', '103030304', '1030304', '102000000'],
        ];
    }

    public function test_fixed_usd_fee_uses_the_same_price_and_never_becomes_a_second_markup_fee(): void
    {
        $quote = app(CryptoQuoteService::class)->calculate('bitcoin-mainnet:BTC', '100', 'USD', '1', '100000', '2', '1', '0.30');

        $this->assertSame('102000', $quote['due_units']);
        $this->assertSame('1320', $quote['fee_units']);
        $this->assertSame('100680', $quote['net_units']);
    }

    public function test_repeating_fraction_rounds_up_without_intermediate_truncation(): void
    {
        $quote = app(CryptoQuoteService::class)->calculate('bitcoin-mainnet:BTC', '1', 'USD', '1', '3', '0', '0', '0');

        $this->assertSame('33333334', $quote['due_units']);
        $this->assertSame('0.33333334', CryptoAmount::format($quote['net_units'], 8));
    }

    public function test_positive_sub_wei_quote_is_rounded_up_instead_of_lost_to_decimal_scale(): void
    {
        $quote = app(CryptoQuoteService::class)->calculate('ethereum-mainnet:ETH', '0.000000000000000000000000000000000001', 'USD', '1', '1000000000000000000000000000000000000000', '0', '0', '0');

        $this->assertSame('1', $quote['due_units']);
        $this->assertSame('0.000000000000000001', CryptoAmount::format($quote['net_units'], 18));
    }

    #[DataProvider('invalidQuotes')]
    public function test_rejects_invalid_quote_inputs(array $changes): void
    {
        $arguments = array_replace(['bitcoin-mainnet:BTC', '100', 'USD', '1', '100000', '2', '1', '0'], $changes);
        $this->expectException(InvalidArgumentException::class);

        app(CryptoQuoteService::class)->calculate(...$arguments);
    }

    public static function invalidQuotes(): array
    {
        return [
            'zero price' => [[4 => '0']],
            'negative amount' => [[1 => '-1']],
            'exponential price' => [[4 => '1e5']],
            'wrong USD rate' => [[3 => '0.98']],
            'fee consumes payment' => [[7 => '200']],
            'fee exceeds 100 percent' => [[6 => '100']],
            'unsupported asset' => [[0 => 'bitcoin-mainnet:USDT']],
            'markup too high' => [[5 => '101']],
            'zero amount' => [[1 => '0']],
            'leading zero decimal' => [[1 => '001']],
        ];
    }

    #[DataProvider('invalidUnits')]
    public function test_rejects_noncanonical_or_overflowing_integer_amounts(string $units): void
    {
        $this->expectException(InvalidArgumentException::class);

        CryptoAmount::units($units);
    }

    public static function invalidUnits(): array
    {
        return [
            'negative' => ['-1'], 'decimal' => ['1.0'], 'leading zero' => ['01'], 'exponent' => ['1e18'],
            'overflow' => ['115792089237316195423570985008687907853269984665640564039457584007913129639936'],
        ];
    }

    public function test_preserves_uint256_without_float_or_database_decimal_conversion(): void
    {
        $this->assertSame(CryptoAmount::MAX_UNITS, CryptoAmount::units(CryptoAmount::MAX_UNITS));
    }
}

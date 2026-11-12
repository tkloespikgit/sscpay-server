<?php

namespace Database\Factories;

use App\Models\CryptoLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoLedgerEntry>
 */
class CryptoLedgerEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'order_paid', 'network' => 'ethereum-sepolia', 'asset' => 'ETH', 'idempotency_key' => 'test:'.fake()->uuid(), 'gross_units' => '100', 'fee_units' => '1', 'amount_units' => '99', 'balance_before_units' => '0', 'balance_after_units' => '99', 'fee_snapshot' => [],
        ];
    }
}

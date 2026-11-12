<?php

namespace Database\Factories;

use App\Models\CryptoAssetBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoAssetBalance>
 */
class CryptoAssetBalanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network' => 'ethereum-sepolia', 'asset' => 'ETH', 'balance_units' => '0', 'reserved_units' => '0',
        ];
    }
}

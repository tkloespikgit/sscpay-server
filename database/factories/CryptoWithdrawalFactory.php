<?php

namespace Database\Factories;

use App\Models\CryptoAssetBalance;
use App\Models\CryptoWithdrawal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CryptoWithdrawal> */
class CryptoWithdrawalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => fn (array $attributes): int => CryptoAssetBalance::query()->withoutGlobalScopes()->findOrFail($attributes['crypto_asset_balance_id'])->merchant_id,
            'network' => 'ethereum-sepolia', 'asset' => 'ETH', 'decimals' => 18,
            'request_key' => fake()->uuid(), 'amount_units' => '1000000000000000', 'fee_limit_units' => '0',
            'reserved_units' => '1000000000000000', 'destination_address' => '0x'.str_repeat('a', 40),
            'status' => 'pending', 'requested_by' => User::factory(),
        ];
    }
}

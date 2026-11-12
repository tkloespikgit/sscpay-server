<?php

namespace Database\Factories;

use App\Models\CryptoWithdrawal;
use App\Models\CryptoWithdrawalEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CryptoWithdrawalEvent> */
class CryptoWithdrawalEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => fn (array $attributes): int => $this->withdrawal($attributes)->merchant_id,
            'crypto_asset_balance_id' => fn (array $attributes): int => $this->withdrawal($attributes)->crypto_asset_balance_id,
            'operator_id' => fn (array $attributes): int => $this->withdrawal($attributes)->requested_by,
            'action' => 'request', 'from_status' => null, 'to_status' => 'pending',
            'balance_before_units' => '1000000000000000', 'balance_after_units' => '1000000000000000',
            'reserved_before_units' => '0', 'reserved_after_units' => '1000000000000000',
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function withdrawal(array $attributes): CryptoWithdrawal
    {
        return CryptoWithdrawal::query()->withoutGlobalScopes()->findOrFail($attributes['crypto_withdrawal_id']);
    }
}

<?php

namespace Database\Factories;

use App\Models\CryptoPaymentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoPaymentRequest>
 */
class CryptoPaymentRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network' => 'ethereum-sepolia', 'asset' => 'ETH', 'decimals' => 18, 'status' => 'preparing', 'due_units' => '1000000000000000', 'fee_units' => '0', 'net_units' => '1000000000000000', 'quote_snapshot' => [], 'expires_at' => now()->addMinutes(15),
        ];
    }
}

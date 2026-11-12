<?php

namespace Database\Factories;

use App\Models\CryptoCheckout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoCheckout>
 */
class CryptoCheckoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fee_snapshot' => ['fee_percent' => '1', 'fee_fixed' => '0'],
            'requested_at' => now(), 'next_attempt_at' => now(), 'status' => 'checking', 'phase' => 'backfill', 'action' => 'open',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\CryptoAddressCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoAddressCheck>
 */
class CryptoAddressCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'day3', 'check_key' => 'day3',
            'cancelled_at' => now()->subDays(3), 'due_at' => now(), 'status' => 'pending',
        ];
    }
}

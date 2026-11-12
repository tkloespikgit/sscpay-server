<?php

namespace Database\Factories;

use App\Models\CryptoChainEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoChainEvent>
 */
class CryptoChainEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network' => 'ethereum-sepolia', 'asset' => 'ETH', 'transaction_hash' => hash('sha256', fake()->uuid()), 'event_position' => 'native', 'event_key' => hash('sha256', fake()->uuid()), 'amount_units' => '1000000000000000', 'block_hash' => hash('sha256', fake()->uuid()), 'block_height' => 1, 'block_time' => now(),
        ];
    }
}

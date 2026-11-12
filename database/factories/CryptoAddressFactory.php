<?php

namespace Database\Factories;

use App\Models\CryptoAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoAddress>
 */
class CryptoAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network' => 'ethereum-sepolia', 'address' => '0x'.str_repeat('a', 40), 'source_fingerprint' => hash('sha256', fake()->uuid()), 'derivation_index' => 0, 'scan_from_block' => 1, 'address_key' => hash('sha256', fake()->uuid()),
        ];
    }
}

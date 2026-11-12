<?php

namespace Database\Factories;

use App\Models\CryptoAddressAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoAddressAllocation>
 */
class CryptoAddressAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'allocation_key' => fake()->uuid(), 'derivation_index' => 2, 'network' => 'ethereum-sepolia', 'asset' => 'ETH', 'status' => 'reserved', 'public_config_snapshot' => [
                'config_version' => '1', 'network' => 'ethereum-sepolia', 'asset' => 'ETH',
                'extended_public_key' => CryptoPublicAccountFactory::EXAMPLE_XPUB,
                'derivation_profile' => 'tatum-v3-ethereum-v1',
                'recovery_address_0' => '0x'.str_repeat('a', 40), 'recovery_address_1' => '0x'.str_repeat('b', 40),
                'confirmation_policy' => 'ethereum-configured-v1',
            ],
        ];
    }
}

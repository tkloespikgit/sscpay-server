<?php

namespace Database\Factories;

use App\Models\CryptoAddressMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoAddressMonitor>
 */
class CryptoAddressMonitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = fake()->uuid();

        return [
            'generation' => 1, 'callback_uuid' => $uuid, 'callback_url' => 'https://notify.example.test/api/webhooks/tatum/testnet/'.$uuid, 'network' => 'ethereum-sepolia', 'environment' => 'testnet', 'credential_fingerprint' => hash('sha256', 'test-testnet-credential'), 'status' => 'preparing', 'ttl_seconds' => 60, 'attempts' => 0,
        ];
    }
}

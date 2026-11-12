<?php

namespace Database\Factories;

use App\Models\CryptoWebhookReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoWebhookReceipt>
 */
class CryptoWebhookReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payload_hash' => hash('sha256', fake()->uuid()), 'transaction_hash' => hash('sha256', fake()->uuid()), 'hint_payload' => [], 'signature_mode' => 'raw', 'status' => 'pending', 'received_at' => now(),
        ];
    }
}

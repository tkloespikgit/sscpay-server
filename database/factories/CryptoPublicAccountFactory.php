<?php

namespace Database\Factories;

use App\Models\CryptoPublicAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CryptoPublicAccount>
 */
class CryptoPublicAccountFactory extends Factory
{
    public const EXAMPLE_XPUB = 'xpub6EsCk1uU6cJzqvP9CdsTiJwT2rF748YkPnhv5Qo8q44DG7nn2vbyt48YRsNSUYS44jFCW9gwvD9kLQu9AuqXpTpM1c5hgg9PsuBLdeNncid';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fingerprint' => hash('sha256', fake()->uuid()), 'family' => 'ethereum', 'extended_public_key' => self::EXAMPLE_XPUB, 'derivation_profile' => 'tatum-v3-ethereum-v1', 'next_index' => 2,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ChargebackProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChargebackProviderAccount> */
class ChargebackProviderAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'provider' => 'wetech',
            'external_id' => Str::random(30),
            'environment' => 'test',
            'timezone' => 'Asia/Shanghai',
            'signing_config' => ['secret_key' => Str::random(32)],
            'services' => ['ethoca', 'rdr'],
            'enabled' => true,
        ];
    }
}

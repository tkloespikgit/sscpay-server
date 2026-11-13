<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\CombinedOrder;
use App\Models\PaymentGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CombinedOrder> */
class CombinedOrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_no' => 'CMB'.Str::ulid(), 'merchant_order_no' => 'COMBINE-'.Str::ulid(),
            'source' => 'api', 'platform' => 'wordpress', 'currency' => 'USD', 'converted_currency' => 'USD',
            'original_amount' => '4000.00', 'amount' => '4000.00', 'converted_amount' => '4000.00',
            'remaining_amount' => '4000.00', 'remaining_converted_amount' => '4000.00',
            'exchange_rate' => '1.000000', 'exchange_snapshot' => [], 'order_snapshot' => [],
            'status' => 'pending', 'payment_link_token' => Str::random(48),
            'checkout_host' => 'localhost', 'checkout_origin' => 'http://localhost', 'expires_at' => now()->addDays(7),
        ];
    }

    public function forApplicationGroup(Application $application, PaymentGroup $group): static
    {
        return $this->state(fn (): array => [
            'merchant_id' => $application->merchant_id, 'application_id' => $application->id,
            'payment_group_id' => $group->id,
        ]);
    }
}

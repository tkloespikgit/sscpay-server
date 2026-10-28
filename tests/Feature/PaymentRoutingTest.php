<?php

namespace Tests\Feature;

use App\Exceptions\NoAvailablePaymentMethodException;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Services\PaymentService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentRoutingTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_each_of_the_three_least_loaded_channels_can_receive_an_order(): void
    {
        $this->freezeTime();
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first', 100);
        $second = $this->attachMethod($group, 'second', 200);
        $third = $this->attachMethod($group, 'third', 100);
        $fourth = $this->attachMethod($group, 'fourth', 100);
        foreach ([[$first, '100.00'], [$second, '100.00'], [$third, '200.00'], [$fourth, '300.00']] as [$method, $amount]) {
            $this->makeOrder('PAID-'.$method->id, 'merchant-'.$method->id, [
                'payment_method_id' => $method->id,
                'payment_method' => $method->method_code,
                'converted_amount' => $amount,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }
        $service = $this->routingService();

        $selected = [
            $service->resolvePaymentMethod($group, 10)->id,
            $service->resolvePaymentMethod($group, 10)->id,
            $service->resolvePaymentMethod($group, 10)->id,
        ];

        $this->assertSame([$first->id, $third->id, $second->id], $selected);
    }

    public function test_pending_orders_allow_random_selection_within_the_first_three_tied_channels(): void
    {
        $this->freezeTime();
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first');
        $second = $this->attachMethod($group, 'second');
        $third = $this->attachMethod($group, 'third');
        $this->attachMethod($group, 'fourth');
        $this->makeOrder('PENDING-SECOND', 'pending-second', [
            'payment_method_id' => $second->id,
            'payment_method' => $second->method_code,
            'status' => 'pending',
        ]);
        $service = $this->routingService();

        $selected = [
            $service->resolvePaymentMethod($group, 10)->id,
            $service->resolvePaymentMethod($group, 10)->id,
            $service->resolvePaymentMethod($group, 10)->id,
        ];

        $this->assertSame([$second->id, $third->id, $first->id], $selected);
    }

    public function test_risk_control_and_enabled_state_are_checked_before_taking_the_first_three(): void
    {
        $group = $this->createGroup();
        $blocked = $this->attachMethod($group, 'blocked', 1000);
        $blocked->update(['max_amount_per_transaction' => '9.00']);
        $inactive = $this->attachMethod($group, 'inactive', 1000);
        $inactive->update(['is_active' => false]);
        $this->attachMethod($group, 'first');
        $second = $this->attachMethod($group, 'second');
        $this->attachMethod($group, 'third');
        $service = $this->routingService();

        $selected = $service->resolvePaymentMethod($group, 10);

        $this->assertSame($second->id, $selected->id);
    }

    public function test_excluded_channels_are_removed_before_selecting_the_next_three(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first');
        $second = $this->attachMethod($group, 'second');
        $this->attachMethod($group, 'third');
        $fourth = $this->attachMethod($group, 'fourth');
        $this->attachMethod($group, 'fifth');
        $service = $this->routingService();

        $selected = $service->resolvePaymentMethod($group, 10, [$first->id, $second->id]);

        $this->assertSame($fourth->id, $selected->id);
    }

    public function test_groups_with_fewer_than_three_remaining_channels_are_supported(): void
    {
        $group = $this->createGroup();
        $first = $this->attachMethod($group, 'first');
        $second = $this->attachMethod($group, 'second');
        $service = $this->routingService();

        $selected = $service->resolvePaymentMethod($group, 10);
        $remaining = $service->resolvePaymentMethod($group, 10, [$selected->id]);

        $this->assertSame($second->id, $selected->id);
        $this->assertSame($first->id, $remaining->id);
    }

    public function test_exhausted_failover_candidates_do_not_send_a_risk_control_alert(): void
    {
        $group = $this->createGroup();
        $method = $this->attachMethod($group, 'tried');
        $service = $this->routingService();

        $this->expectException(NoAvailablePaymentMethodException::class);

        $service->resolvePaymentMethod($group, 10, [$method->id]);
    }

    public function test_initial_routing_without_candidates_still_sends_an_alert(): void
    {
        $group = $this->createGroup();
        $this->mock(TelegramNotificationService::class)
            ->shouldReceive('send')->once()
            ->with($group->merchant_id, \Mockery::type('string'))
            ->andReturn(true);

        $this->expectException(NoAvailablePaymentMethodException::class);

        app(PaymentService::class)->resolvePaymentMethod($group, 10);
    }

    private function createGroup(): PaymentGroup
    {
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();

        return PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'routing',
            'group_name' => 'Routing',
            'is_active' => true,
        ]);
    }

    private function attachMethod(PaymentGroup $group, string $code, int $weight = 100): PaymentMethod
    {
        $method = $this->makePaymentMethod($code);
        $group->paymentMethods()->attach($method->id, ['priority' => $weight]);

        return $method;
    }

    private function routingService(): PaymentService
    {
        $this->mock(TelegramNotificationService::class)->shouldNotReceive('send');
        $this->instance(Randomizer::class, new Randomizer(new Mt19937(1)));

        return app(PaymentService::class);
    }
}

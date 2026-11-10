<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentPageTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMerchantAndApplication();
    }

    public function test_pending_payment_link_redirects_to_gateway(): void
    {
        $order = $this->makeOrder('SSC-PAGE', 'M-PAGE', [
            'status' => 'pending',
            'pay_url' => 'https://shop.example.com/pay/order-1',
        ]);

        $this->get(route('payment.show', $order->payment_link_token))
            ->assertRedirect('https://shop.example.com/pay/order-1');
    }

    public function test_payment_link_without_gateway_url_displays_order(): void
    {
        $order = $this->makeOrder('SSC-PAGE', 'M-PAGE', ['status' => 'pending']);

        $this->get(route('payment.show', $order->payment_link_token))
            ->assertOk()
            ->assertViewIs('payment.show')
            ->assertSee($order->order_no);
    }

    public function test_self_referencing_payment_link_displays_order_without_redirect_loop(): void
    {
        $order = $this->makeOrder('SSC-PAGE', 'M-PAGE', ['status' => 'pending']);
        $url = route('payment.show', $order->payment_link_token);
        $order->update(['pay_url' => $url]);

        $this->get($url)->assertOk()->assertViewIs('payment.show');
    }

    public function test_expired_payment_link_does_not_redirect_to_gateway(): void
    {
        $this->freezeTime();
        $order = $this->makeOrder('SSC-PAGE', 'M-PAGE', [
            'status' => 'pending',
            'pay_url' => 'https://shop.example.com/pay/order-1',
        ]);

        $order->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->get(route('payment.show', $order->payment_link_token))
            ->assertOk()->assertViewIs('payment.expired');
    }
}

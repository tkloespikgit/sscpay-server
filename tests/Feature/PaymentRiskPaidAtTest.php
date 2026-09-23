<?php

namespace Tests\Feature;

use App\Exceptions\NoAvailablePaymentMethodException;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Services\PaymentService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentRiskPaidAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_and_monthly_limits_use_payment_date_across_month_boundary(): void
    {
        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-09-30 23:00:00');

        $merchant = Merchant::create([
            'name' => 'Test Merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'merchant@example.com',
        ]);
        $application = Application::createWithCredentials([
            'merchant_id' => $merchant->id,
            'name' => 'Test App',
            'website' => 'https://shop.example.com',
        ]);
        $group = PaymentGroup::create([
            'merchant_id' => $merchant->id,
            'group_key' => 'default',
            'group_name' => 'Default',
            'is_active' => true,
        ]);
        $method = PaymentMethod::create([
            'merchant_id' => $merchant->id,
            'method_code' => 'test_method',
            'method_name' => 'Test Method',
            'is_active' => true,
            'max_amount_per_day' => 100,
        ]);
        $group->paymentMethods()->attach($method->id, ['priority' => 1]);

        $order = Order::create([
            'merchant_id' => $merchant->id,
            'application_id' => $application->id,
            'merchant_order_no' => 'CROSS-MONTH-001',
            'currency' => 'USD',
            'subtotal' => '100.00',
            'shipping_fee' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'amount' => '100.00',
            'converted_amount' => '100.00',
            'subtotal_converted' => '100.00',
            'shipping_fee_converted' => '0.00',
            'discount_converted' => '0.00',
            'tax_converted' => '0.00',
            'exchange_rate' => '1.000000',
            'original_exchange_rate' => '1.000000',
            'surcharge_percent' => '0.0000',
            'surcharge_amount' => '0.000000',
            'surcharge_fee' => '0.00',
            'customer_first_name' => 'John',
            'customer_last_name' => 'Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => '123456',
            'shipping_address_line1' => '123 Main St',
            'shipping_city' => 'City',
            'shipping_country' => 'US',
            'shipping_zip' => '12345',
            'payment_method' => $method->method_code,
            'payment_method_id' => $method->id,
            'status' => 'paid',
        ]);

        $order->update(['paid_at' => Carbon::parse('2026-10-01 01:00:00')]);
        Carbon::setTestNow('2026-10-01 12:00:00');

        try {
            app(PaymentService::class)->resolvePaymentMethod($group, 1);
            $this->fail('The payment date should fill the October 1 daily limit.');
        } catch (NoAvailablePaymentMethodException) {
            $this->assertTrue(true);
        }

        $method->update(['max_amount_per_day' => 0, 'max_amount_per_month' => 100]);
        Carbon::setTestNow('2026-10-02 12:00:00');

        $this->expectException(NoAvailablePaymentMethodException::class);
        app(PaymentService::class)->resolvePaymentMethod($group, 1);
    }
}

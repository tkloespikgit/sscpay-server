<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use Database\Seeders\PermissionSeeder;

/**
 * orders 表有三十来个非空列，测试里逐个手写既噪音大又容易漏；
 * 这里给出一份"能落库的最小合法订单"，各测试只覆盖自己关心的字段。
 */
trait CreatesTestOrders
{
    protected Merchant $merchant;

    protected Application $application;

    protected function createMerchantAndApplication(): void
    {
        // MerchantObserver 建商户时会给它开一套商户级角色，角色引用的权限
        // 必须先存在，否则 spatie 直接抛 PermissionDoesNotExist。
        $this->seed(PermissionSeeder::class);

        $this->merchant = Merchant::create([
            'name' => 'Test Merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'merchant@example.com',
        ]);

        $this->application = Application::createWithCredentials([
            'merchant_id' => $this->merchant->id,
            'name' => 'Test App',
            'website' => 'https://shop.example.com',
        ]);
    }

    protected function makePaymentMethod(string $code): PaymentMethod
    {
        $configMap = PaymentMethodConfigMap::firstOrCreate(
            ['payment_config_tag' => 'stripe'],
            ['name' => 'Test Gateway', 'fields' => []],
        );

        return PaymentMethod::create([
            'merchant_id' => $this->merchant->id,
            'method_code' => $code,
            'method_name' => strtoupper($code),
            'is_active' => true,
            'config_map_id' => $configMap->id,
            'domain' => 'https://shop.example.com',
            'domain_client_id' => 'ck_test',
            'domain_client_sk' => 'cs_test',
            'product_match_mode' => PaymentMethod::MODE_MATCH,
        ]);
    }

    protected function makeOrder(string $orderNo, string $merchantOrderNo, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'application_id' => $this->application->id,
            'order_no' => $orderNo,
            'merchant_order_no' => $merchantOrderNo,
            'payment_link_token' => 'tok-'.$orderNo,
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
            'customer_phone' => '1234567890',
            'shipping_address_line1' => '123 Main St',
            'shipping_city' => 'City',
            'shipping_country' => 'US',
            'shipping_zip' => '12345',
            'payment_method' => 'test_method',
        ], $overrides));
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Carbon\Carbon;
use Tests\TestCase;

class OrderConvertedAmountDisplayTest extends TestCase
{
    public function test_crypto_checkout_list_amount_uses_three_decimals(): void
    {
        $order = new Order([
            'converted_amount' => '99.997',
            'crypto_receive_address' => 'TTestReceiveAddressCryptoPoC00001',
            'crypto_expires_at' => Carbon::now()->addHour(),
        ]);

        $this->assertSame(
            '$99.997',
            OrderResource::convertedAmountState($order, $order->converted_amount),
        );
    }

    public function test_non_crypto_list_amount_uses_two_decimals(): void
    {
        $order = new Order([
            'converted_amount' => '100.000',
            'crypto_receive_address' => null,
            'crypto_expires_at' => null,
        ]);

        $this->assertSame(
            '$100.00',
            OrderResource::convertedAmountState($order, $order->converted_amount),
        );
    }

    public function test_converted_amount_cell_can_scale_for_observer(): void
    {
        $order = new Order([
            'converted_amount' => '99.997',
            'crypto_receive_address' => 'TTestReceiveAddressCryptoPoC00001',
            'crypto_expires_at' => Carbon::now()->addHour(),
        ]);

        $this->assertSame(
            '$49.998',
            OrderResource::convertedAmountState(
                $order,
                $order->converted_amount,
                fn (string $amount): string => bcmul($amount, '0.5', 3),
            ),
        );
    }
}

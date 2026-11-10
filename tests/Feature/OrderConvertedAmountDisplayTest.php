<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Tests\TestCase;

class OrderConvertedAmountDisplayTest extends TestCase
{
    public function test_list_amount_uses_two_decimals(): void
    {
        $order = new Order(['converted_amount' => '100.00']);

        $this->assertSame('100.00', $order->converted_amount);
        $this->assertSame(
            '$100.00',
            OrderResource::convertedAmountState($order, $order->converted_amount),
        );
    }

    public function test_converted_amount_cell_can_scale_for_observer(): void
    {
        $order = new Order(['converted_amount' => '99.98']);

        $this->assertSame(
            '$49.99',
            OrderResource::convertedAmountState(
                $order,
                $order->converted_amount,
                fn (string $amount): string => bcmul($amount, '0.5', 2),
            ),
        );
    }
}

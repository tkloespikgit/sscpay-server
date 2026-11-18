<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Services\OrderAfterSaleService;

final class OrderAfterSaleDisplay
{
    public static function chargebackFee(Order $order): string
    {
        return '$'.number_format((float) OrderAfterSaleService::chargebackFee($order), 2, '.', ',');
    }

    public static function chargebackFeeStatus(Order $order): string
    {
        return __('admin.order.after_sale.'.(OrderAfterSaleService::chargebackFeePending($order) ? 'pending_accounting' : 'accounted'));
    }

    public static function origin(Order $order): string
    {
        return __('admin.order.after_sale.'.(OrderAfterSaleService::isRefundConversion($order) ? 'refund_conversion' : 'direct_chargeback'));
    }
}

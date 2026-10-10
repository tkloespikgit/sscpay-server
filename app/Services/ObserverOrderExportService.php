<?php

namespace App\Services;

use App\Models\Observer;
use App\Support\ObserverOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ObserverOrderExportService
{
    /** 导出列表展示字段，保留筛选并重新限定观察者授权；金额只输出折算值。 */
    public function download(Observer $observer, Builder $query): StreamedResponse
    {
        $query = clone $query;
        $query->whereIn('orders.payment_method_id', $observer->paymentMethods()->withoutGlobalScopes()->select('payment_methods.id'))
            ->whereIn('orders.status', ObserverOrderStatus::visibleStatuses())
            ->select('orders.*')
            ->with(['shipping', 'paymentMethod.merchant']);

        return response()->streamDownload(function () use ($observer, $query) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                __('admin.order.columns.order_no'), __('admin.order.columns.merchant_name'),
                __('admin.order.columns.payment_method'), __('admin.order.columns.status'),
                __('admin.order.fields.tracking_number'), __('admin.order.columns.amount_original'),
                __('admin.order.fields.currency'), __('admin.order.columns.amount_usd'),
                __('admin.order.columns.created_at'),
            ]);

            foreach ($query->lazyById(500) as $order) {
                $row = [
                    $order->order_no, $order->paymentMethod?->merchant?->name,
                    $order->paymentMethod?->method_name ?? $order->payment_method,
                    ObserverOrderStatus::label($order->status), $order->shipping?->tracking_number,
                    $observer->scaleAmount($order->amount), strtoupper($order->currency),
                    $observer->scaleAmount($order->converted_amount), $order->created_at?->format('Y-m-d H:i:s'),
                ];
                // 避免可控文本被 Excel 当作公式执行。
                $row = array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $row);
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'observer_orders_'.now()->format('Ymd_His').'.csv');
    }
}

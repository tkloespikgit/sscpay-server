<?php

namespace App\Services;

use App\Models\Observer;
use App\Support\ObserverOrderStatus;
use App\Support\XlsxExport;
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
            ->with(['shipping', 'paymentMethod', 'merchant.owner']);

        return response()->streamDownload(function () use ($observer, $query): void {
            $rows = (function () use ($observer, $query): \Generator {
                yield XlsxExport::row([
                    __('observer.orders.columns.agent_name'), __('observer.orders.columns.merchant_name'),
                    __('observer.orders.columns.order_no'), __('observer.orders.columns.merchant_order_no'),
                    __('observer.orders.columns.transaction_id'),
                    __('admin.order.columns.payment_method'), __('admin.order.columns.status'),
                    __('observer.orders.columns.amount'), __('observer.orders.columns.converted_amount'),
                    __('observer.orders.columns.settlement_amount'),
                    __('admin.order.columns.created_at'),
                    __('admin.order.columns.customer_email'), __('admin.order.fields.currency'),
                    __('admin.order.fields.tracking_number'),
                ]);

                foreach ($query->lazyById(500) as $order) {
                    yield XlsxExport::row([
                        $order->merchant?->owner?->name ?? __('admin.merchant.placeholders.owner_platform'),
                        $order->merchant?->name, $order->order_no, $order->merchant_order_no, $order->transaction_id,
                        $order->paymentMethod?->method_name ?? $order->payment_method,
                        ObserverOrderStatus::label($order->status), $observer->scaleAmount($order->amount),
                        $observer->scaleAmount($order->converted_amount),
                        $order->payment_type === 'crypto' ? null : $observer->scaleAmount($order->settlement_amount),
                        $order->created_at?->format('Y-m-d H:i:s'), $order->customer_email,
                        strtoupper($order->currency), $order->shipping?->tracking_number,
                    ], [7, 8, 9]);
                }
            })();

            XlsxExport::write($rows);
        }, 'observer_orders_'.now()->format('Ymd_His').'.xlsx', ['Content-Type' => XlsxExport::CONTENT_TYPE]);
    }
}

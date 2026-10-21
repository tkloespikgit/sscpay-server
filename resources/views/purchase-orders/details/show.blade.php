<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('purchase_order.detail.title', ['number' => $purchaseOrder->order_no]) }}</title>
    @include('purchase-orders.details.styles')
</head>
<body>
    @include('purchase-orders.details.header')

    <main class="detail-wrapper">
        @include('purchase-orders.details.status')

        <div class="detail-tabs" role="tablist" aria-label="{{ __('purchase_order.document.details_summary') }}">
            <button type="button" class="detail-tab active" id="order-tab" role="tab" aria-selected="true" aria-controls="order-panel" tabindex="0">{{ __('purchase_order.detail.tab_order') }}</button>
            <button type="button" class="detail-tab" id="logistics-tab" role="tab" aria-selected="false" aria-controls="logistics-panel" tabindex="-1">{{ __('purchase_order.detail.tab_logistics') }}</button>
            <button type="button" class="detail-tab" id="transaction-tab" role="tab" aria-selected="false" aria-controls="transaction-panel" tabindex="-1">{{ __('purchase_order.detail.tab_transaction') }}</button>
        </div>
        <section class="detail-card" aria-label="{{ __('purchase_order.document.details_summary') }}">
            <div class="detail-panel order-panel" id="order-panel" role="tabpanel" aria-labelledby="order-tab">
                @include('purchase-orders.details.information')
                @include('purchase-orders.details.items')

                <div class="service-summary">
                    <span style="color: black">退货包运费</span>
                    <span>上门取件，自动减免首重运费</span>
                    <span style="color: orangered">查看服务</span>
                    <span aria-hidden="true">›</span>
                </div>
                <div class="service-summary">
                    <span style="color: black">48小时发货</span>
                    <span>订单承诺限时发货，延迟可理赔</span>
                    <span style="color: orangered">申请理赔</span>
                    <span aria-hidden="true">›</span>
                </div>
                <dl class="amount-summary">
                    <div><dt>{{ __('purchase_order.detail.subtotal') }}</dt><dd class="money">¥ {{ $purchaseOrder->subtotal }}</dd></div>
                    <div><dt>{{ __('purchase_order.detail.shipping_fee') }}</dt><dd class="money">¥ {{ $purchaseOrder->shipping_fee }}</dd></div>
                    <div class="discount-row"><dt>{{ __('purchase_order.detail.total_discount') }}</dt><dd>{{ __('purchase_order.detail.discount_prefix') }} <span class="money">¥ {{ $purchaseOrder->discount }}</span></dd></div>
                    <div class="paid-row">
                        <img src="{{asset('img/ask_icon.png')}}" style="height: 12px" alt="">
                        <dt> {{ __('purchase_order.detail.paid_total') }}</dt>
                        <dd class="money">¥ <strong>{{ $purchaseOrder->total }}</strong></dd>
                    </div>
                </dl>
            </div>

            @include('purchase-orders.details.other-panels')

            <div class="print-bar"><button type="button" class="orange-button" id="print-order-details">{{ __('purchase_order.detail.print') }}</button></div>
        </section>
    </main>

    @include('purchase-orders.details.footer')
    <button type="button" class="back-top" id="details-back-top" aria-label="{{ __('purchase_order.detail.back_top') }}"><span aria-hidden="true"></span></button>
    @include('purchase-orders.details.scripts')
</body>
</html>

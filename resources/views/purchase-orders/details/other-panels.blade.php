<div class="detail-panel alternate-panel" id="logistics-panel" role="tabpanel" aria-labelledby="logistics-tab" hidden>
    <h2>{{ __('purchase_order.detail.tab_logistics') }}</h2>
    <dl class="information-list">
        <div><dt>{{ __('purchase_order.detail.shipping_company') }}</dt><dd>{{ $detailFields['shippingCompany'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.tracking_number') }}</dt><dd>{{ $detailFields['trackingNumber'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.recipient') }}</dt><dd>{{ $detailFields['buyerContactName'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.company') }}</dt><dd>{{ $detailFields['buyerCompany'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.address') }}</dt><dd>{{ $detailFields['buyerAddress'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.mobile') }}</dt><dd>{{ $detailFields['buyerPhone'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.email') }}</dt><dd>{{ $detailFields['buyerEmail'] }}</dd></div>
        <div><dt>{{ __('purchase_order.fields.shipped_at') }}</dt><dd>{{ $purchaseOrder->shipped_at->format('Y-m-d H:i:s') }}</dd></div>
        <div><dt>{{ __('purchase_order.fields.received_at') }}</dt><dd>{{ $purchaseOrder->received_at->format('Y-m-d H:i:s') }}</dd></div>
    </dl>
    <p class="alternate-note">{{ __('purchase_order.detail.logistics_note') }}</p>
    <h2>{{ __('purchase_order.detail.seller_information') }}</h2>
    <dl class="information-list">
        <div><dt>{{ __('purchase_order.detail.supplier') }}</dt><dd>{{ $sellerCompany }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.contact_name') }}</dt><dd>{{ $detailFields['sellerContactName'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.mobile') }}</dt><dd>{{ $detailFields['sellerPhone'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.address') }}</dt><dd>{{ $detailFields['sellerAddress'] }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.email') }}</dt><dd>{{ $detailFields['sellerEmail'] }}</dd></div>
    </dl>
</div>
<div class="detail-panel alternate-panel" id="transaction-panel" role="tabpanel" aria-labelledby="transaction-tab" hidden>
    <h2>{{ __('purchase_order.detail.tab_transaction') }}</h2>
    <dl class="information-list">
        <div><dt>{{ __('purchase_order.detail.order_number') }}</dt><dd>{{ $purchaseOrder->order_no }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.transaction_number') }}</dt><dd>{{ $purchaseOrder->transaction_id }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.payment_method') }}</dt><dd>{{ $detailFields['paymentMethodName'] }}</dd></div>
        <div><dt>{{ __('purchase_order.document.ordering_account') }}</dt><dd>{{ $buyerAccount }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.currency') }}</dt><dd>{{ __('purchase_order.detail.cny') }}</dd></div>
        <div><dt>{{ __('purchase_order.fields.paid_at') }}</dt><dd>{{ $purchaseOrder->paid_at->format('Y-m-d H:i:s') }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.subtotal') }}</dt><dd class="money">¥ {{ $purchaseOrder->subtotal }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.shipping_fee') }}</dt><dd class="money">¥ {{ $purchaseOrder->shipping_fee }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.total_discount') }}</dt><dd class="money">¥ {{ $purchaseOrder->discount }}</dd></div>
        <div><dt>{{ __('purchase_order.detail.paid_total') }}</dt><dd class="money">¥ {{ $purchaseOrder->total }}</dd></div>
    </dl>
    <p class="alternate-note">{{ __('purchase_order.detail.status_note') }}</p>
    <a class="orange" href="{{ $paymentMethodUrl }}">{{ __('purchase_order.document.return_payment_method') }} &gt;</a>
</div>

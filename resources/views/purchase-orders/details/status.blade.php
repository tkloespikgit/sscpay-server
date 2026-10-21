<section class="status-card" aria-label="{{ __('purchase_order.detail.current_status') }}">
    <div class="timeline">
        <ol>
            <li><i class="timeline-dot" aria-hidden="true"></i><strong>{{ __('purchase_order.detail.step_ordered') }}</strong><time datetime="{{ $purchaseOrder->ordered_at->toIso8601String() }}">{{ $purchaseOrder->ordered_at->format('Y-m-d H:i:s') }}</time></li>
            <li><i class="timeline-dot" aria-hidden="true"></i><strong>{{ __('purchase_order.detail.step_paid') }}</strong><time datetime="{{ $purchaseOrder->paid_at->toIso8601String() }}">{{ $purchaseOrder->paid_at->format('Y-m-d H:i:s') }}</time></li>
            <li><i class="timeline-dot" aria-hidden="true"></i><strong>{{ __('purchase_order.detail.step_shipped') }}</strong><time datetime="{{ $purchaseOrder->shipped_at->toIso8601String() }}">{{ $purchaseOrder->shipped_at->format('Y-m-d H:i:s') }}</time></li>
            <li><i class="timeline-dot" aria-hidden="true"></i><strong>{{ __('purchase_order.detail.step_received') }}</strong><time datetime="{{ $purchaseOrder->received_at->toIso8601String() }}">{{ $purchaseOrder->received_at->format('Y-m-d H:i:s') }}</time></li>
        </ol>
    </div>
    <div class="status-information">
        <h1>{{ __('purchase_order.detail.current_status') }} <span>{{ __('purchase_order.detail.status_success') }}</span></h1>
        <ol>
            <li>
                交易成功，请给卖家 <button type="button" class="orange-button" id="print-order-details">评价</button>
            </li>
            <li>如果没有收到货，或收到货后出现问题，您可以联系卖家协商解决</li>
            <li>如果卖家没有履行应尽的承诺，您可以提交投诉，进行维权</li>
            <li><a href="{{ route('purchase-orders.show', ['purchaseOrder' => $purchaseOrder->getKey()]) }}">添加备忘</a></li>
        </ol>
    </div>
</section>

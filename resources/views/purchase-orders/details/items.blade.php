<table class="items-table">
    <colgroup><col class="goods"><col class="unit"><col class="count"><col class="discount"><col class="state"></colgroup>
    <thead>
        <tr>
            <th scope="col">{{ __('purchase_order.detail.goods') }}</th>
            <th scope="col">{{ __('purchase_order.detail.unit_price') }}</th>
            <th scope="col">{{ __('purchase_order.detail.quantity') }}</th>
            <th scope="col">{{ __('purchase_order.detail.discount') }}</th>
            <th scope="col">{{ __('purchase_order.detail.item_status') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($purchaseOrder->items as $item)
            <tr>
                <td>
                    <div class="item-description">
                        @if ($itemImageUrls[$item->id] ?? null)
                            <img class="item-image" src="{{ $itemImageUrls[$item->id] }}" alt="{{ $item->product_name }}" loading="lazy" referrerpolicy="no-referrer">
                        @else
                            <div class="item-image item-image-placeholder" role="img" aria-label="{{ __('purchase_order.document.no_image') }}">{{ __('purchase_order.document.no_image') }}</div>
                        @endif
                        <div>
                            <h3 class="item-title">{{ $item->product_name }}</h3>
                            @if (filled($item->sku))
                                <div class="item-meta">{{ __('purchase_order.detail.sku') }}: {{ $item->sku }}</div>
                            @endif
                            <div class="item-perks">
                                @foreach ($perks_words as $perk)
                                    <span>{{ $perk }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="line-subtotal"><span class="money"> {{ $item->unit_price }} </span> 元/个</div>
                </td>
                <td>{{ $item->quantity }}</td>
                <td class="order-level-discount" title="{{ __('purchase_order.detail.order_discount_note') }}">-</td>
                <td>{{ __('purchase_order.detail.item_received') }}</td>
            </tr>
        @empty
            <tr><td colspan="5">{{ __('purchase_order.document.items_empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

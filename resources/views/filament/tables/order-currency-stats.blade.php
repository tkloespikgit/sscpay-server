{{-- 订单列表页表格上方的"本次查询统计"（通过 Table::header() 渲染，出现在筛选栏之上）。 --}}
<div class="fi-ta-order-currency-stats border-b border-gray-200 px-4 py-3 dark:border-white/10" style="padding: 10px 20px">
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1.5 text-sm text-gray-950 dark:text-white">
        <b class="font-semibold">{{ __('admin.order.stats.heading') }}:</b>

        @forelse ($stats as $row)
            <p>
                <span class="font-semibold">{{ $row->currency }}</span>
                &nbsp;
                {{ __('admin.order.stats.orders_count') }} <span class="font-semibold">{{ (int) $row->orders_count }}</span>
                &nbsp;·&nbsp;
                {{ __('admin.order.stats.total_amount') }} <span class="font-semibold">{{ number_format((float) $row->total_amount, 2) }} {{ $row->currency }}</span>
                &nbsp;·&nbsp;
                {{ __('admin.order.stats.total_amount_usd') }} <span class="font-semibold">${{ number_format((float) $row->total_converted_amount, 2) }}</span>
            </p>
        @empty
            <p class="text-gray-500 dark:text-gray-400">{{ __('admin.order.stats.empty') }}</p>
        @endforelse

        @if ($stats->count() > 1)
            <p class="border-l border-gray-300 pl-6 dark:border-gray-700">
                {{ __('admin.order.stats.grand_total') }}:
                <span class="font-semibold">{{ (int) $stats->sum('orders_count') }}</span>
                {{ __('admin.order.stats.orders') }}
                ·
                <span class="font-semibold">${{ number_format((float) $stats->sum('total_converted_amount'), 2) }}</span>
            </p>
        @endif
    </div>
</div>

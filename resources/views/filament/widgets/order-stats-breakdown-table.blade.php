{{--
    订单统计看板的分组明细表。

    样式走 Filament 自带的 fi-* 类 + inline style，不写新的 Tailwind 类名——
    本项目没有自定义 Filament 主题（不跑 Tailwind 构建），新类名不会被编译进样式表。
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('admin.order_stats.charts.breakdown', ['dimension' => $dimensionLabel]) }}
        </x-slot>

        <x-slot name="description">
            {{ $timezoneNotice }}
        </x-slot>

        @if ($rows->isEmpty())
            <p style="color: rgb(113 113 122); font-size: 0.875rem;">
                {{ __('admin.order_stats.empty') }}
            </p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid rgba(0, 0, 0, 0.1);">
                            <th style="padding: 0.5rem 0.75rem; font-weight: 600;">{{ $dimensionLabel }}</th>
                            @foreach (['paid', 'failed', 'refunded', 'chargeback'] as $metric)
                                <th style="padding: 0.5rem 0.75rem; font-weight: 600; text-align: right;">
                                    {{ __('admin.order_stats.metrics.'.$metric) }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-bottom: 1px solid rgba(0, 0, 0, 0.05);">
                                <td style="padding: 0.5rem 0.75rem;">{{ $row->dimension_label }}</td>
                                @foreach (['paid', 'failed', 'refunded', 'chargeback'] as $metric)
                                    <td style="padding: 0.5rem 0.75rem; text-align: right;">
                                        <span style="font-weight: 600;">${{ number_format((float) $row->{$metric.'_amount'}, 2) }}</span>
                                        <span style="color: rgb(113 113 122);">
                                            / {{ number_format((int) $row->{$metric.'_orders'}) }}
                                        </span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p style="margin-top: 0.75rem; color: rgb(113 113 122); font-size: 0.75rem;">
                {{ __('admin.order_stats.amount_order_hint') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

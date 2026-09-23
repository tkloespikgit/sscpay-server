<?php

namespace App\Filament\Widgets;

use App\Models\Application;
use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Services\DashboardService;
use App\Services\OrderStatsQueryService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * 按选定维度（支付方式 / 应用 / 商户）分组的四指标明细表。
 *
 * 用自绘 Blade 而不是 Filament 的 TableWidget：TableWidget 要求查询返回可 hydrate
 * 的模型行，而这里是 GROUP BY 聚合结果（没有 id、分页 count 也会算错）。
 * 行数天然有界（一个周期内的商户/应用/渠道数量），不需要分页。
 */
class OrderStatsBreakdownTable extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.order-stats-breakdown-table';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{dimensionLabel: string, rows: Collection<int, object>, timezoneNotice: string}
     */
    protected function getViewData(): array
    {
        $dimension = $this->resolveDimension();
        $merchantIds = DashboardService::viewerMerchantIds();

        $rows = app(OrderStatsQueryService::class)->breakdown(
            $merchantIds,
            $this->filters['period'] ?? null,
            $dimension,
            $this->filters ?? [],
        );

        $names = $this->dimensionNames($dimension, $rows->pluck('dimension_id')->all());

        $rows->each(function ($row) use ($names) {
            $row->dimension_label = $names[(int) $row->dimension_id]
                ?? __('admin.order_stats.unknown_dimension');
        });

        return [
            'dimensionLabel' => __('admin.order_stats.filters.'.match ($dimension) {
                'merchant_id' => 'merchant',
                'application_id' => 'application',
                default => 'payment_method',
            }),
            'rows' => $rows,
            'timezoneNotice' => __('admin.order_stats.timezone_notice'),
        ];
    }

    /**
     * $filters 来自前端可随意赋值的 Livewire 属性，必须收敛到白名单；
     * 商户维度还要再挡一次，商户用户不该按商户分组。
     */
    private function resolveDimension(): string
    {
        $dimension = $this->filters['dimension'] ?? 'payment_method_id';

        if (! in_array($dimension, ['payment_method_id', 'application_id', 'merchant_id'], true)) {
            return 'payment_method_id';
        }

        if ($dimension === 'merchant_id' && ! auth()->user()?->isPlatformStaff()) {
            return 'payment_method_id';
        }

        return $dimension;
    }

    /**
     * 维度 ID -> 展示名称。支付方式用 withTrashed()：渠道删除后历史统计还在，
     * 不带出来就只剩一个光秃秃的 ID。
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function dimensionNames(string $dimension, array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));

        if ($ids === []) {
            return [];
        }

        return match ($dimension) {
            'merchant_id' => Merchant::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'application_id' => Application::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            default => PaymentMethod::query()->withoutGlobalScopes()->withTrashed()
                ->whereIn('id', $ids)->pluck('method_name', 'id')->all(),
        };
    }
}

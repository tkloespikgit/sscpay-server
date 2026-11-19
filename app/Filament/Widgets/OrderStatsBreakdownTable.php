<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\OrderStats;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\OrderStatsQueryService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * 按选定维度（支付方式 / 应用 / 商户 / 代理）分组的四指标明细表。
 *
 * 用自绘 Blade 而不是 Filament 的 TableWidget：TableWidget 要求查询返回可 hydrate
 * 的模型行，而这里是 GROUP BY 聚合结果（没有 id、分页 count 也会算错）。
 * 行数天然有界（一个周期内的商户/应用/渠道/代理数量），不需要分页。
 */
class OrderStatsBreakdownTable extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.order-stats-breakdown-table';

    protected int|string|array $columnSpan = 'full';

    #[Locked]
    public ?string $fixedDimension = null;

    public static function canView(): bool
    {
        return OrderStats::canAccess();
    }

    /**
     * @return array{heading: string, dimensionLabel: string, rows: Collection<int, object>, timezoneNotice: string}
     */
    protected function getViewData(): array
    {
        abort_unless(static::canView(), 403);

        $dimension = $this->resolveDimension();
        $merchantIds = DashboardService::viewerMerchantIds();
        $filters = $this->pageFilters ?? [];
        $stats = app(OrderStatsQueryService::class);

        if ($this->fixedDimension === null) {
            $rows = $stats->breakdown($merchantIds, $filters['period'] ?? null, $dimension, $filters);
        } else {
            $query = app(DashboardService::class)->statsQuery($merchantIds, $filters);
            $rows = $dimension === 'owner_id'
                ? $stats->breakdownByOwner($query)
                : $stats->breakdownFromQuery($query, $dimension);
        }

        if ($dimension === 'owner_id') {
            $rows->each(function ($row): void {
                $row->dimension_label = $row->dimension_name ?? __('admin.dashboard.direct_management');
            });
        } else {
            $names = $this->dimensionNames($dimension, $rows->pluck('dimension_id')->all());

            $rows->each(function ($row) use ($names): void {
                $row->dimension_label = $names[(int) $row->dimension_id]
                    ?? __('admin.order_stats.unknown_dimension');
            });
        }

        $dimensionKey = match ($dimension) {
            'owner_id' => 'owner',
            'merchant_id' => 'merchant',
            'application_id' => 'application',
            default => 'payment_method',
        };
        $dimensionLabel = $dimension === 'owner_id'
            ? __('admin.dashboard.filters.owner')
            : __('admin.order_stats.filters.'.$dimensionKey);

        return [
            'heading' => $this->fixedDimension === null
                ? __('admin.order_stats.charts.breakdown', ['dimension' => $dimensionLabel])
                : __('admin.dashboard.breakdowns.'.$dimensionKey),
            'dimensionLabel' => $dimensionLabel,
            'rows' => $rows,
            'timezoneNotice' => __('admin.order_stats.timezone_notice'),
        ];
    }

    /**
     * 旧统计页的分组筛选来自 Livewire，必须收敛到白名单；
     * 商户维度还要再挡一次，商户用户不该按商户分组。
     */
    private function resolveDimension(): string
    {
        if ($this->fixedDimension === 'owner_id') {
            $viewer = auth()->user();

            abort_unless($viewer instanceof User && $viewer->is_super_admin, 403);

            return 'owner_id';
        }

        $dimension = $this->fixedDimension ?? $this->pageFilters['dimension'] ?? 'payment_method_id';

        if (! in_array($dimension, OrderStatsQueryService::BREAKDOWN_DIMENSIONS, true)) {
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

<?php

namespace App\Filament\Observer\Resources;

use App\Filament\Observer\Resources\OrderResource\Pages;
use App\Models\Observer;
use App\Models\Order;
use App\Models\PaymentMethod;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 观察者账户自己登录后看到的订单列表：完全只读（不提供 create/edit/delete/任何
 * 写操作），范围严格限定在该观察者绑定的支付方式下。这是观察者面板
 * （ObserverPanelProvider，guard 'observer'）专属 Resource，故意不放在共享的
 * app/Filament/Resources 目录——那批 Resource 到处直接用裸 auth()->user()，
 * 假设登录用户是 App\Models\User，混进来会在 Observer 请求下出问题（见
 * ObserverPanelProvider 类注释）。这里一律显式用 auth('observer')->user()。
 *
 * 金额类列一律经过 Observer::scaleAmount() 折算后展示，不碰底层真实金额。
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    public static function getModelLabel(): string
    {
        return __('observer.orders.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('observer.orders.model_label_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('observer.orders.nav_label');
    }

    public static function currentObserver(): ?Observer
    {
        /** @var ?Observer $observer */
        $observer = auth('observer')->user();

        return $observer;
    }

    /**
     * 只看该观察者绑定支付方式下的订单，orders.payment_method_id 下单时
     * 必填必写（见 OrderCreationService::createRemotePayment()），可以直接拿来
     * whereIn 用，不需要靠 merchant_id + payment_method 编码兜底拼查询。
     * withoutGlobalScopes()：绕开 Order 和 PaymentMethod 各自的 MerchantScope——
     * 那个 scope 假设 auth()->user() 是 User，观察者面板下 Filament 已经把
     * 默认 guard 切到 'observer'，auth()->user() 会解析成 Observer 实例，
     * MerchantScope 里调 $user->manageableMerchantIds() 直接报
     * BadMethodCallException（实测踩过一次），这里两处查询都要显式绕开。
     */
    public static function getEloquentQuery(): Builder
    {
        $observer = static::currentObserver();

        $paymentMethodIds = $observer
            ? $observer->paymentMethods()->withoutGlobalScopes()->pluck('payment_methods.id')
            : collect();

        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->whereIn('payment_method_id', $paymentMethodIds);
    }

    public static function table(Table $table): Table
    {
        $observer = static::currentObserver();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shipping', 'paymentMethod.merchant']))
            ->columns([
                TextColumn::make('order_no')->label(__('admin.order.columns.order_no'))->searchable()->copyable(),
                TextColumn::make('paymentMethod.merchant.name')->label(__('admin.order.columns.merchant_name')),
                TextColumn::make('paymentMethod.method_name')->label(__('admin.order.columns.payment_method'))
                    ->formatStateUsing(fn (?string $state, Order $record) => $state ?? $record->payment_method),

                TextColumn::make('status')->label(__('admin.order.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.order.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'gray',
                        'paid' => 'info',
                        'shipped' => 'warning',
                        'completed' => 'success',
                        'disputing' => 'warning',
                        'dispute_review' => 'warning',
                        'partially_refunded' => 'warning',
                        'cancelled', 'failed', 'expired', 'refunded', 'chargeback' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('shipping.tracking_number')->label(__('admin.order.columns.shipping_status'))
                    ->placeholder(__('admin.order.placeholders.not_shipped'))
                    ->color(fn (?Order $record) => $record?->shipping ? 'primary' : 'gray'),

                TextColumn::make('amount')->label(__('admin.order.columns.amount_original'))
                    ->getStateUsing(fn (Order $record) => $observer?->scaleAmount($record->amount))
                    ->formatStateUsing(fn ($state, Order $record) => number_format((float) $state, 2).' '.strtoupper((string) $record->currency))
                    ->sortable(),
                TextColumn::make('converted_amount')->label(__('admin.order.columns.amount_usd'))
                    ->getStateUsing(fn (Order $record) => $observer?->scaleAmount($record->converted_amount))
                    ->money('usd')
                    ->sortable(),

                TextColumn::make('created_at')->label(__('admin.order.columns.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('payment_method_id')
                    ->label(__('admin.order.filters.payment_method'))
                    ->options(fn () => $observer
                        ? $observer->paymentMethods()->withoutGlobalScopes()->with('merchant')->get()
                            ->mapWithKeys(fn (PaymentMethod $method) => [
                                $method->id => ($method->merchant?->name ?? __('admin.payment_method.columns.system_level'))." - {$method->method_name}",
                            ])
                        : []),

                SelectFilter::make('status')->label(__('admin.order.filters.status'))->options([
                    'pending' => __('admin.order.statuses.pending'),
                    'paid' => __('admin.order.statuses.paid'),
                    'shipped' => __('admin.order.statuses.shipped'),
                    'completed' => __('admin.order.statuses.completed'),
                    'cancelled' => __('admin.order.statuses.cancelled'),
                    'failed' => __('admin.order.statuses.failed'),
                    'expired' => __('admin.order.statuses.expired'),
                    'disputing' => __('admin.order.statuses.disputing'),
                    'dispute_review' => __('admin.order.statuses.dispute_review'),
                    'partially_refunded' => __('admin.order.statuses.partially_refunded'),
                    'refunded' => __('admin.order.statuses.refunded'),
                    'chargeback' => __('admin.order.statuses.chargeback'),
                ]),

                // 发货状态判定方式对齐 App\Filament\Resources\OrderResource（以是否存在
                // 物流记录为准，不按 status 字段判断）。
                SelectFilter::make('shipping_status')
                    ->label(__('admin.order.filters.shipping_status'))
                    ->options([
                        'shipped' => __('admin.order.filters.shipping_shipped'),
                        'unshipped' => __('admin.order.filters.shipping_unshipped'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(($data['value'] ?? null) === 'shipped', fn ($q) => $q->whereHas('shipping'))
                            ->when(($data['value'] ?? null) === 'unshipped', fn ($q) => $q->doesntHave('shipping'));
                    }),

                Filter::make('order_no')
                    ->label(__('admin.order.filters.order_no'))
                    ->schema([
                        TextInput::make('order_no')
                            ->label(__('admin.order.filters.order_no'))
                            ->placeholder(__('admin.order.filters.order_no_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['order_no'] ?? null,
                        fn ($q, $orderNo) => $q->where('order_no', 'like', '%'.$orderNo.'%')
                    )),

                Filter::make('transaction_id')
                    ->label(__('admin.order.filters.transaction_id'))
                    ->schema([
                        TextInput::make('transaction_id')
                            ->label(__('admin.order.filters.transaction_id'))
                            ->placeholder(__('admin.order.filters.transaction_id_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['transaction_id'] ?? null,
                        fn ($q, $transactionId) => $q->where('transaction_id', 'like', '%'.$transactionId.'%')
                    )),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * 列表页底部"本次查询统计"，写法对齐 App\Filament\Resources\OrderResource::currencyStats()
     * （同一个 render hook 渲染逻辑见 ObserverPanelProvider，同一份 Blade 视图
     * resources/views/filament/tables/order-currency-stats.blade.php）。
     * 原始金额/折算 USD 两个合计都要经 Observer::scaleAmount() 折算——比例是单一乘数，
     * 折算后再求和 与 求和后再折算 数学上等价，这里选择后者，只需按币种分组聚合
     * 一次，不用逐行折算再汇总。订单数不折算。
     */
    public static function currencyStats($livewire): Collection
    {
        $query = $livewire->getFilteredSortedTableQuery();

        if (! $query) {
            return collect();
        }

        $observer = static::currentObserver();

        return $query->toBase()
            ->reorder()
            ->groupBy('currency')
            ->orderBy('currency')
            ->selectRaw('currency, COUNT(*) as orders_count, SUM(amount) as total_amount, SUM(converted_amount) as total_converted_amount')
            ->get()
            ->each(function ($row) use ($observer) {
                $row->total_amount = $observer?->scaleAmount($row->total_amount) ?? $row->total_amount;
                $row->total_converted_amount = $observer?->scaleAmount($row->total_converted_amount) ?? $row->total_converted_amount;
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}

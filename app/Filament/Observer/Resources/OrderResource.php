<?php

namespace App\Filament\Observer\Resources;

use App\Filament\Observer\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource as AdminOrderResource;
use App\Models\Merchant;
use App\Models\Observer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\ObserverOrderQueryService;
use App\Support\ObserverOrderStatus;
use App\Support\OrderStatusColor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

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
            ->whereIn('payment_method_id', $paymentMethodIds)
            ->whereIn('status', ObserverOrderStatus::visibleStatuses());
    }

    public static function table(Table $table): Table
    {
        $observer = static::currentObserver();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shipping.operator', 'paymentMethod', 'merchant.owner']))
            ->columns([
                TextColumn::make('merchant.owner.name')->label(__('observer.orders.columns.agent_name'))
                    ->placeholder(__('admin.merchant.placeholders.owner_platform')),
                TextColumn::make('merchant.name')->label(__('observer.orders.columns.merchant_name'))
                    ->placeholder(__('admin.order.placeholders.none')),
                TextColumn::make('order_no')->label(__('observer.orders.columns.order_no'))->searchable()->copyable(),
                TextColumn::make('merchant_order_no')->label(__('observer.orders.columns.merchant_order_no'))->copyable(),
                TextColumn::make('transaction_id')->label(__('observer.orders.columns.transaction_id'))
                    ->copyable()
                    ->placeholder(__('admin.order.placeholders.none')),
                TextColumn::make('paymentMethod.method_name')->label(__('admin.order.columns.payment_method'))
                    ->formatStateUsing(fn (?string $state, Order $record) => $state ?? $record->payment_method),

                TextColumn::make('status')->label(__('admin.order.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ObserverOrderStatus::label($state))
                    ->color(fn (string $state): string => OrderStatusColor::get($state)),

                AdminOrderResource::shippingInfoColumn(),

                TextColumn::make('amount')->label(__('observer.orders.columns.amount'))
                    ->formatStateUsing(fn ($state, Order $record): string|HtmlString => AdminOrderResource::originalCurrencyAmountState(
                        $record,
                        $record->amount,
                        fn (string $amount): string => (string) ($observer?->scaleAmount($amount) ?? $amount),
                    ))
                    ->html()
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('converted_amount')->label(__('observer.orders.columns.converted_amount'))
                    ->formatStateUsing(fn ($state, Order $record): string => AdminOrderResource::convertedAmountState(
                        $record,
                        $record->converted_amount,
                        fn (string $amount): string => (string) ($observer?->scaleAmount($amount) ?? $amount),
                    ))
                    ->sortable(),
                TextColumn::make('settlement_amount')->label(__('observer.orders.columns.settlement_amount'))
                    ->state(fn (Order $record): ?string => $record->payment_type === 'crypto'
                        ? null : $observer?->scaleAmount($record->settlement_amount))
                    ->formatStateUsing(fn ($state, Order $record): string => AdminOrderResource::convertedAmountState($record, $state))
                    ->placeholder(__('admin.order.placeholders.none'))
                    ->sortable(),

                TextColumn::make('created_at')->label(__('admin.order.columns.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('agent_id')
                    ->label(__('observer.orders.columns.agent_name'))
                    ->options(function (): array {
                        $merchants = static::visibleMerchantsQuery();
                        $options = User::query()
                            ->whereIn('id', (clone $merchants)->select('owner_id'))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();

                        if ((clone $merchants)->whereNull('owner_id')->exists()) {
                            $options = ['platform' => __('admin.merchant.placeholders.owner_platform')] + $options;
                        }

                        return $options;
                    })
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q): Builder => $q->whereHas('merchant', fn (Builder $merchantQuery): Builder => $data['value'] === 'platform'
                                ? $merchantQuery->whereNull('owner_id')
                                : $merchantQuery->where('owner_id', $data['value']))
                    )),

                SelectFilter::make('merchant_id')
                    ->label(__('observer.orders.columns.merchant_name'))
                    ->options(fn () => static::visibleMerchantsQuery()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                Filter::make('created_from')
                    ->schema([
                        DatePicker::make('created_from')->label(__('admin.order.filters.created_from')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['created_from'] ?? null,
                        fn (Builder $q, $date): Builder => $q->whereDate('orders.created_at', '>=', $date)
                    )),

                Filter::make('created_to')
                    ->schema([
                        DatePicker::make('created_to')->label(__('admin.order.filters.created_to')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['created_to'] ?? null,
                        fn (Builder $q, $date): Builder => $q->whereDate('orders.created_at', '<=', $date)
                    )),

                SelectFilter::make('payment_method_id')
                    ->label(__('admin.order.filters.payment_method'))
                    ->options(fn () => $observer
                        ? $observer->paymentMethods()->withoutGlobalScopes()->with('merchant')->get()
                            ->mapWithKeys(fn (PaymentMethod $method) => [
                                $method->id => ($method->merchant?->name ?? __('admin.payment_method.columns.system_level'))." - {$method->method_name}",
                            ])
                        : []),

                Filter::make('order_no')
                    ->label(__('observer.orders.columns.order_no'))
                    ->schema([
                        TextInput::make('order_no')
                            ->label(__('observer.orders.columns.order_no'))
                            ->placeholder(__('admin.order.filters.order_no_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        trim((string) ($data['order_no'] ?? '')) !== '',
                        fn (Builder $q): Builder => $q->where('orders.order_no', 'like', '%'.trim((string) $data['order_no']).'%')
                    )),

                Filter::make('merchant_order_no')
                    ->label(__('observer.orders.columns.merchant_order_no'))
                    ->schema([
                        TextInput::make('merchant_order_no')
                            ->label(__('observer.orders.columns.merchant_order_no'))
                            ->placeholder(__('admin.order.filters.merchant_order_no_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        trim((string) ($data['merchant_order_no'] ?? '')) !== '',
                        fn (Builder $q): Builder => $q->where('orders.merchant_order_no', 'like', '%'.trim((string) $data['merchant_order_no']).'%')
                    )),

                Filter::make('transaction_id')
                    ->label(__('observer.orders.columns.transaction_id'))
                    ->schema([
                        TextInput::make('transaction_id')
                            ->label(__('observer.orders.columns.transaction_id'))
                            ->placeholder(__('admin.order.filters.transaction_id_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        trim((string) ($data['transaction_id'] ?? '')) !== '',
                        fn (Builder $q): Builder => $q->where('orders.transaction_id', 'like', '%'.trim((string) $data['transaction_id']).'%')
                    )),

                Filter::make('amount')
                    ->label(__('observer.orders.columns.amount'))
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('observer.orders.columns.amount'))
                            ->inputMode('decimal')
                            ->placeholder('100.00'),
                    ])
                    ->query(fn (Builder $query, array $data, ObserverOrderQueryService $service): Builder => $observer
                        ? $service->filterByDisplayedAmount($query, $observer, (string) ($data['amount'] ?? ''))
                        : $query),

                Filter::make('customer_email')
                    ->label(__('admin.order.filters.customer_email'))
                    ->schema([
                        TextInput::make('customer_email')
                            ->label(__('admin.order.filters.customer_email'))
                            ->placeholder(__('admin.order.filters.customer_email_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        trim((string) ($data['customer_email'] ?? '')) !== '',
                        fn (Builder $q): Builder => $q->where('orders.customer_email', 'like', '%'.trim((string) $data['customer_email']).'%')
                    )),

                SelectFilter::make('status')->label(__('admin.order.filters.status'))
                    ->options(ObserverOrderStatus::options())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->whereIn('status', ObserverOrderStatus::GROUPS[$data['value']] ?? [])
                    )),

                SelectFilter::make('shipping_status')
                    ->label(__('admin.order.filters.shipping_status'))
                    ->options([
                        'shipped' => __('admin.order.filters.shipping_shipped'),
                        'unshipped' => __('admin.order.filters.shipping_unshipped'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(($data['value'] ?? null) === 'shipped', fn (Builder $q): Builder => $q->whereHas('shipping'))
                        ->when(($data['value'] ?? null) === 'unshipped', fn (Builder $q): Builder => $q->doesntHave('shipping'))),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->recordActions([])
            ->defaultSort('created_at', 'desc');
    }

    private static function visibleMerchantsQuery(): Builder
    {
        return Merchant::query()->whereIn('id', static::getEloquentQuery()->select('orders.merchant_id'));
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

        // 聚合表达式直接复用管理端那份（AdminOrderResource::currencyStatsSelect()）：
        // 两边渲染同一个 Blade，字段少一个视图里就会取到 undefined property。
        return $query->toBase()
            ->reorder()
            ->groupBy('currency')
            ->orderBy('currency')
            ->selectRaw(...AdminOrderResource::currencyStatsSelect())
            ->get()
            ->each(function ($row) use ($observer) {
                $row->total_amount = $observer?->scaleAmount($row->total_amount) ?? $row->total_amount;
                $row->total_converted_amount = $observer?->scaleAmount($row->total_converted_amount) ?? $row->total_converted_amount;
                $row->paid_converted_amount = $observer?->scaleAmount($row->paid_converted_amount) ?? $row->paid_converted_amount;
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

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerServiceOrderResource\Pages;
use App\Models\Order;
use App\Services\OrderPaymentStatusService;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Support\OrderStatusColor;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 客服订单查询：给客服角色用的精简只读列表。
 *
 * 【与 OrderResource 的区别】
 * 客服往往只需要回答"这笔订单查到没有、客户邮箱是什么、三方交易号是多少"，
 * 不需要、也不应该看到结算金额（涉及平台与商户分成）、物流、发货等信息。
 * 因此这里刻意不复用 OrderResource：
 *   - 列只保留系统订单号、商户订单号、原始币种金额、三方交易号、客户邮箱、
 *     订单状态与创建时间；前五项作为查询条件，至少填写一项并提交后才显示结果；
 *   - 不带 currencyStats 汇总统计（汇总会把金额口径直接暴露在页脚）；
 *   - 不带 ViewAction（详情页信息量远大于列表，不适合客服角色）。
 *
 * 【权限隔离】
 * 单独用 customer_service_orders.view，而不是复用 orders.view：
 * 客服角色只配这一个权限，不会顺带拿到完整订单列表与详情页入口。
 * 商户维度隔离沿用商户侧已有的 MerchantScope（全局限额见 User::manageableMerchantIds），
 * 本类不重复加 merchant_id 约束——加了反而会和全局作用域叠加出空结果。
 *
 * 【保留的能力】
 * 行操作保留"查询订单"（与完整订单列表一致）：调 OrderPaymentStatusService
 * 主动向网关核对最新支付状态，可能真实改变订单状态——这是刻意保留的，
 * 客服需要用它在客户来问"我付了吗"时给出准确答复。
 */
class CustomerServiceOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.order_management');
    }

    public static function getModelLabel(): string
    {
        return __('admin.customer_service_order.labels.order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.customer_service_order.labels.orders');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.customer_service_order.nav');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query, Pages\ListCustomerServiceOrders $livewire): Builder => $query->when(
                ! $livewire->hasSearchFilters(),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0')
            ))
            ->columns([
                TextColumn::make('order_no')
                    ->label(__('admin.order.columns.order_no'))
                    ->copyable()
                    // 与完整订单列表一致：订单进人工争议审核后订单号标红，便于客服识别异常单。
                    ->color(fn ($record) => $record->activeDisputeEvent ? 'danger' : null)
                    ->sortable(),

                TextColumn::make('merchant_order_no')
                    ->label(__('admin.order.columns.merchant_order_no'))
                    ->copyable()
                    ->placeholder(__('admin.order.placeholders.none')),

                TextColumn::make('transaction_id')
                    ->label(__('admin.order.columns.transaction_id'))
                    ->copyable()
                    ->placeholder(__('admin.order.placeholders.none')),

                TextColumn::make('customer_email')
                    ->label(__('admin.order.columns.customer_email'))
                    ->copyable()
                    ->placeholder(__('admin.order.placeholders.none')),

                // 原始币种金额（如 100.00 EUR）。刻意不展示 converted_amount /
                // settlement_amount：那是平台与商户的分成口径，不属于客服的职责范围。
                TextColumn::make('amount')
                    ->label(__('admin.order.columns.amount_original'))
                    ->formatStateUsing(fn ($state, $record) => number_format((float) $state, 2).' '.strtoupper((string) $record->currency))
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('admin.order.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __('admin.order.statuses.'.$state))
                    ->color(fn (string $state): string => OrderStatusColor::get($state))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('admin.order.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            // 客服列表不做汇总：Summarizer 会在页脚暴露金额口径合计。
            ->filters([
                Filter::make('order_no')
                    ->label(__('admin.order.filters.order_no'))
                    ->schema([
                        TextInput::make('order_no')
                            ->label(__('admin.order.filters.order_no'))
                            ->placeholder(__('admin.order.filters.exact_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        trim((string) ($data['order_no'] ?? '')) !== '',
                        fn ($q) => $q->where('order_no', trim((string) $data['order_no']))
                    )),

                Filter::make('merchant_order_no')
                    ->label(__('admin.order.filters.merchant_order_no'))
                    ->schema([
                        TextInput::make('merchant_order_no')
                            ->label(__('admin.order.filters.merchant_order_no'))
                            ->placeholder(__('admin.order.filters.exact_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        trim((string) ($data['merchant_order_no'] ?? '')) !== '',
                        fn ($q) => $q->where('merchant_order_no', trim((string) $data['merchant_order_no']))
                    )),

                Filter::make('amount')
                    ->label(__('admin.order.columns.amount_original'))
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('admin.order.columns.amount_original'))
                            ->inputMode('decimal')
                            ->placeholder('100.00'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $amount = trim((string) ($data['amount'] ?? ''));

                        if ($amount === '') {
                            return $query;
                        }

                        // 非法输入直接返回空集，不要悄悄放宽成"忽略该条件"——
                        // 客服填错金额时若照着旧结果回复，答的是另一笔订单。
                        if (! is_numeric($amount)) {
                            return $query->whereRaw('1 = 0');
                        }

                        return $query->where('amount', $amount);
                    }),

                Filter::make('transaction_id')
                    ->label(__('admin.order.filters.transaction_id'))
                    ->schema([
                        TextInput::make('transaction_id')
                            ->label(__('admin.order.filters.transaction_id'))
                            ->placeholder(__('admin.order.filters.transaction_id_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        trim((string) ($data['transaction_id'] ?? '')) !== '',
                        fn ($q) => $q->where('transaction_id', 'like', '%'.trim((string) $data['transaction_id']).'%')
                    )),

                Filter::make('customer_email')
                    ->label(__('admin.order.filters.customer_email'))
                    ->schema([
                        TextInput::make('customer_email')
                            ->label(__('admin.order.filters.customer_email'))
                            ->placeholder(__('admin.order.filters.customer_email_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        trim((string) ($data['customer_email'] ?? '')) !== '',
                        fn ($q) => $q->where('customer_email', 'like', '%'.trim((string) $data['customer_email']).'%')
                    )),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action): Action => $action
                ->label(__('admin.customer_service_order.search.action'))
                ->action('searchOrders'))
            ->emptyStateHeading(fn (Pages\ListCustomerServiceOrders $livewire): string => $livewire->hasSearchFilters()
                ? __('admin.customer_service_order.search.no_results')
                : __('admin.customer_service_order.search.required'))
            ->emptyStateDescription(null)
            ->defaultSort('created_at', 'desc')
            // 只保留"查询订单"，不给 ViewAction/查看客户信息/查看物流。
            // 刻意不设 toolbarActions([])/bulkActions([])——OrderResource 也没设，
            // 保持同一套默认行为，避免这里额外引入"清空工具栏"带来的耦合差异。
            ->recordActions([
                static::queryStatusAction(),
            ]);
    }

    /**
     * 「查询订单」：主动向支付网关核对这笔订单的最新状态。
     *
     * 行为与完整订单列表里的同名操作一致（调 OrderPaymentStatusService::queryAndApply）：
     * 查到已支付会把订单纠正为 paid 并补做入账/通知，这是刻意保留的副作用——
     * 客服要靠它回答客户"我到底付没付钱"。
     */
    public static function queryStatusAction(): Action
    {
        return Action::make('queryOrderStatus')
            ->label(__('admin.order.actions.query_status'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn () => (bool) auth()->user()?->can(Permissions::CUSTOMER_SERVICE_ORDERS_VIEW))
            ->action(function ($record) {
                try {
                    $result = app(OrderPaymentStatusService::class)->queryAndApply($record);
                } catch (PaymentGatewayException $e) {
                    Notification::make()
                        ->title($e->isOrderNotFound()
                            ? __('admin.order.actions.query_status_not_found')
                            : __('admin.order.actions.query_status_failed', ['error' => $e->getMessage()]))
                        ->danger()
                        ->send();

                    return;
                } catch (\RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                if ($result['mapped_status'] === null) {
                    Notification::make()
                        ->title(__('admin.order.actions.query_status_unknown', ['status' => $result['queried_status']]))
                        ->warning()
                        ->send();

                    return;
                }

                if ($result['changed']) {
                    Notification::make()
                        ->title(__('admin.order.actions.query_status_changed', [
                            'old' => __('admin.order.statuses.'.$result['old_status']),
                            'new' => __('admin.order.statuses.'.$result['new_status']),
                        ]))
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title(__('admin.order.actions.query_status_unchanged', [
                            'status' => __('admin.order.statuses.'.$result['new_status']),
                        ]))
                        ->success()
                        ->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerServiceOrders::route('/'),
        ];
    }

    /** 没有新建入口：客服只查询，不建单。 */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    /** 没有详情页，canView 一律 false。 */
    public static function canView($record): bool
    {
        return false;
    }

    /** 导航与列表入口由客服专属权限控制。 */
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::CUSTOMER_SERVICE_ORDERS_VIEW);
    }
}

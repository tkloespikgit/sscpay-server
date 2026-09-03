<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\OrderPaymentStatusService;
use App\Services\OrderShippingService;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 订单管理（4.4 / 7.7 节）。列表支持商户、应用、支付方式、状态、
 * 客户邮箱、发货状态、平台、创建时间等筛选（常驻展示在表格上方）；
 * 详情页（ViewOrder）承担了"商品明细 + 物流信息 + 事件时间线 + 通知记录"
 * 的完整展示，见该 Page 类。手工建单走独立的 CreateManualOrder 页面
 * （不是标准的 Resource::create()，因为表单结构和下单流程都不一样）。
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.order_management');
    }

    public static function getModelLabel(): string
    {
        return __('admin.order.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.order.model_label_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            // shipping.tracking_number 列会自动触发 shipping 的预加载，这里额外带上
            // shipping.operator，避免点击"物流信息"弹窗时才现查触发懒加载；
            // paymentMethod 用于把 payment_method 列从 method_code 换成 method_name 展示。
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shipping.operator', 'paymentMethod']))
            ->columns([
                // 商户名称列仅超级管理员可见（商户用户本来就只看自己的订单）
                TextColumn::make('merchant.name')->label(__('admin.order.columns.merchant_name'))
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin)
                    ->searchable(),
                TextColumn::make('application.app_id')->label(__('admin.order.columns.application'))
                    ->formatStateUsing(fn ($record) => $record->application
                        ? $record->application->app_id.' - '.$record->application->name
                        : __('admin.order.placeholders.none'))
                    ->searchable(['applications.app_id', 'applications.name'])
                    ->toggleable(),

                TextColumn::make('order_no')->label(__('admin.order.columns.order_no'))->searchable()->copyable(),
                TextColumn::make('merchant_order_no')->label(__('admin.order.columns.merchant_order_no'))->searchable(),
                TextColumn::make('transaction_id')->label(__('admin.order.columns.transaction_id'))
                    ->searchable()
                    ->copyable()
                    ->placeholder(__('admin.order.placeholders.none'))
                    ->toggleable(),

                TextColumn::make('status')->label(__('admin.order.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.order.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'gray',
                        'paid' => 'info',
                        'shipped' => 'warning',
                        'completed' => 'success',
                        'disputing' => 'warning',
                        'partially_refunded' => 'warning',
                        'cancelled', 'failed', 'expired', 'refunded', 'chargeback' => 'danger',
                        default => 'gray',
                    }),
                // 展示 payment_methods.method_name；老数据/关联已被硬删除时兜底回退到
                // 冗余存的 method_code，保证列不会空着。
                TextColumn::make('paymentMethod.method_name')->label(__('admin.order.columns.payment_method'))
                    ->formatStateUsing(fn (?string $state, $record) => $state ?? $record->payment_method),

                // 提交的原始币种金额（如 100.00 EUR）
                TextColumn::make('amount')->label(__('admin.order.columns.amount_original'))
                    ->formatStateUsing(fn ($state, $record) => number_format((float) $state, 2).' '.strtoupper((string) $record->currency))
                    ->sortable(),
                TextColumn::make('converted_amount')->label(__('admin.order.columns.amount_usd'))->money('usd')->sortable(),
                TextColumn::make('customer_email')->label(__('admin.order.columns.customer_email'))
                    ->color('primary')
                    ->action(static::viewCustomerInfoAction()),

                TextColumn::make('shipping.tracking_number')->label(__('admin.order.columns.shipping_status'))
                    ->placeholder(__('admin.order.placeholders.not_shipped'))
                    ->color(fn ($record) => $record->shipping ? 'primary' : 'gray')
                    ->action(static::viewShippingInfoAction()),

                TextColumn::make('platform')->label(__('admin.order.columns.platform')),
                TextColumn::make('created_at')->label(__('admin.order.columns.created_at'))->dateTime()->sortable(),
            ])
            // "本次查询统计"这行渲染在搜索栏下方、数据行上方，用的是
            // TablesRenderHook::TOOLBAR_AFTER（见 AdminPanelProvider），不是 header()/
            // contentFooter()——Table 自带的这两个扩展点只能落在"筛选栏上方"或
            // "表格 <tfoot>"，没有"搜索栏下方"这个位置。
            ->filters([
                // 商户筛选仅超级管理员可见；商户用户的数据本身已被 MerchantScope 限制
                SelectFilter::make('merchant_id')
                    ->label(__('admin.order.filters.merchant'))
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin)
                    ->options(fn () => Merchant::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                // Application 自带 MerchantScope，商户用户只会看到自己名下的应用；
                // 超级管理员可看到全平台应用。选项展示为 "app_id - 名称"。
                SelectFilter::make('application_id')
                    ->label(__('admin.order.filters.application'))
                    ->options(fn () => Application::orderBy('name')->get(['id', 'app_id', 'name'])
                        ->mapWithKeys(fn ($app) => [$app->id => $app->app_id.' - '.$app->name]))
                    ->searchable(),

                SelectFilter::make('payment_method')
                    ->label(__('admin.order.filters.payment_method'))
                    ->options(function () {
                        $user = auth()->user();

                        // 不加 withoutGlobalScopes()，交给 MerchantScope 自动隔离：
                        // 商户用户只会看到自己商户的支付方式，不会出现其他商户的配置。
                        if (! $user?->is_super_admin) {
                            return PaymentMethod::query()
                                ->orderBy('sort_order')
                                ->pluck('method_name', 'method_code');
                        }

                        // 超级管理员看到全部支付方式，按商户分组展示，便于区分不同商户
                        // 配置的同名/同 code 支付方式（按 code 筛选时会同时命中这些商户的订单）。
                        return PaymentMethod::query()
                            ->with('merchant')
                            ->orderBy('sort_order')
                            ->get()
                            ->groupBy(fn (PaymentMethod $method) => $method->merchant?->name ?? '-')
                            ->map(fn ($methods) => $methods->pluck('method_name', 'method_code'))
                            ->toArray();
                    }),

                // 选项来自系统配置 order.platforms（见 Order::supportedPlatforms()）
                SelectFilter::make('platform')
                    ->label(__('admin.order.filters.platform'))
                    ->options(fn () => array_combine($platforms = Order::supportedPlatforms(), $platforms)),

                SelectFilter::make('status')->label(__('admin.order.filters.status'))->options([
                    'pending' => __('admin.order.statuses.pending'),
                    'paid' => __('admin.order.statuses.paid'),
                    'shipped' => __('admin.order.statuses.shipped'),
                    'completed' => __('admin.order.statuses.completed'),
                    'cancelled' => __('admin.order.statuses.cancelled'),
                    'failed' => __('admin.order.statuses.failed'),
                    'expired' => __('admin.order.statuses.expired'),
                    'disputing' => __('admin.order.statuses.disputing'),
                    'partially_refunded' => __('admin.order.statuses.partially_refunded'),
                    'refunded' => __('admin.order.statuses.refunded'),
                    'chargeback' => __('admin.order.statuses.chargeback'),
                ]),

                // 发货状态：以是否存在物流记录（order_shippings）为准，
                // 不按订单 status 字段判断（订单可能已完成但补录物流等）。
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

                Filter::make('customer_email')
                    ->label(__('admin.order.filters.customer_email'))
                    ->schema([
                        TextInput::make('customer_email')
                            ->label(__('admin.order.filters.customer_email'))
                            ->placeholder(__('admin.order.filters.customer_email_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['customer_email'] ?? null,
                        fn ($q, $email) => $q->where('customer_email', 'like', '%'.$email.'%')
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

                // 创建时间拆成“开始时间 / 结束时间”两个独立筛选框，
                // 各自占一格，避免两个日期控件堆叠在同一个单元格里造成换行。
                Filter::make('created_from')
                    ->label(__('admin.order.filters.created_from'))
                    ->schema([
                        DatePicker::make('created_from')->label(__('admin.order.filters.created_from')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['created_from'] ?? null,
                        fn ($q, $date) => $q->whereDate('created_at', '>=', $date)
                    )),

                Filter::make('created_to')
                    ->label(__('admin.order.filters.created_to'))
                    ->schema([
                        DatePicker::make('created_to')->label(__('admin.order.filters.created_to')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['created_to'] ?? null,
                        fn ($q, $date) => $q->whereDate('created_at', '<=', $date)
                    )),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->recordActions([
                ViewAction::make(),
                static::queryStatusAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * "查询最新状态"（文档第七节 /order-query）：列表行内和详情页头部共用同一个
     * Action 定义，避免两处各写一遍、改文案的时候漏改一处。用 ORDERS_VIEW 权限
     * 把关而不是单独开一个权限——这是个"拉取插件侧最新数据"的只读触发动作，
     * 谁能看订单谁就能点，跟退款/拒付那种真正的资金操作不是一回事。
     */
    public static function queryStatusAction(): Action
    {
        return Action::make('queryOrderStatus')
            ->label(__('admin.order.actions.query_status'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn () => (bool) auth()->user()?->can(Permissions::ORDERS_VIEW))
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

    /**
     * 列表页"客户邮箱"列点击弹窗：展示当前订单快照里的客户信息（姓名、邮箱、
     * 手机号、收货地址）。订单表自己就冗余存了这些字段（下单时快照，不随
     * 用户后续修改资料而变），所以直接用只读 infolist 展示本记录即可，不需要
     * 额外查询客户表。
     */
    public static function viewCustomerInfoAction(): Action
    {
        return Action::make('viewCustomerInfo')
            ->label(__('admin.order.columns.customer_email'))
            ->modalHeading(__('admin.order.modals.customer_info_heading'))
            ->modalSubmitAction(false)
            ->modalCancelAction(fn (Action $action) => $action->label(__('admin.order.modals.close')))
            ->schema([
                Grid::make(2)->schema([
                    TextEntry::make('customer_first_name')->label(__('admin.order.fields.customer_first_name'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('customer_last_name')->label(__('admin.order.fields.customer_last_name'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('customer_email')->label(__('admin.order.fields.customer_email'))->copyable(),
                    TextEntry::make('customer_phone')->label(__('admin.order.fields.customer_phone'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping_address_line1')->label(__('admin.order.fields.address'))
                        ->placeholder(__('admin.order.placeholders.none'))
                        ->columnSpanFull(),
                    TextEntry::make('shipping_address_line2')->label(__('admin.order.fields.address_line2'))
                        ->placeholder(__('admin.order.placeholders.none'))
                        ->columnSpanFull(),
                    TextEntry::make('shipping_city')->label(__('admin.order.fields.city'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping_state')->label(__('admin.order.fields.state'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping_zip')->label(__('admin.order.fields.zip'))
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping_country')->label(__('admin.order.fields.country'))
                        ->placeholder(__('admin.order.placeholders.none')),
                ]),
            ]);
    }

    /**
     * 列表页"发货状态"列点击弹窗：展示完整物流信息。未发货订单没有 shipping
     * 关联，点击直接禁用（isDisabled 时 Livewire 会静默不挂载弹窗），
     * 列文案已经通过 placeholder 显示"未发货"，无需再弹一个空弹窗。
     */
    public static function viewShippingInfoAction(): Action
    {
        return Action::make('viewShippingInfo')
            ->label(__('admin.order.columns.shipping_status'))
            ->modalHeading(__('admin.order.modals.shipping_info_heading'))
            ->modalSubmitAction(false)
            ->modalCancelAction(fn (Action $action) => $action->label(__('admin.order.modals.close')))
            ->disabled(fn ($record) => ! $record->shipping)
            ->schema([
                TextEntry::make('shipping.logistics_company')->label(__('admin.order.fields.logistics_company'))
                    ->placeholder(__('admin.order.placeholders.none')),
                TextEntry::make('shipping.tracking_number')->label(__('admin.order.fields.tracking_number'))
                    ->copyable()
                    ->placeholder(__('admin.order.placeholders.none')),
                TextEntry::make('shipping.tracking_url')->label(__('admin.order.fields.tracking_url'))
                    ->url(fn ($record) => $record->shipping?->tracking_url)
                    ->openUrlInNewTab()
                    ->placeholder(__('admin.order.placeholders.none')),
                TextEntry::make('shipping.shipped_at')->label(__('admin.order.fields.shipped_at'))
                    ->dateTime()
                    ->placeholder(__('admin.order.placeholders.none')),
                TextEntry::make('shipping.operator.name')->label(__('admin.order.fields.operator'))
                    ->state(fn ($record) => $record->shipping?->operator_id === OrderShippingService::API_OPERATOR_ID
                        ? 'API'
                        : $record->shipping?->operator?->name)
                    ->placeholder(__('admin.order.placeholders.none')),
                TextEntry::make('shipping.remark')->label(__('admin.order.fields.remark'))
                    ->placeholder(__('admin.order.placeholders.none'))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * 列表页底部"本次查询统计"：按币种分组统计原始金额合计 / 折算 USD 合计 / 订单笔数，
     * 基于当前筛选 + 搜索后的完整结果集（不受分页影响，见 getFilteredSortedTableQuery()）。
     * 用 toBase() 退化成普通 query builder 再做 groupBy 聚合——避免用 Eloquent 语义
     * 对聚合行（缺 id 等字段）做模型 hydration / 关联预加载。
     */
    public static function currencyStats($livewire): \Illuminate\Support\Collection
    {
        $query = $livewire->getFilteredSortedTableQuery();

        if (! $query) {
            return collect();
        }

        return $query->toBase()
            // 清掉表格默认的 created_at 排序：GROUP BY 聚合查询里保留非聚合列的
            // ORDER BY，在 MySQL ONLY_FULL_GROUP_BY 模式下会直接报错。
            ->reorder()
            ->groupBy('currency')
            ->orderBy('currency')
            ->selectRaw('currency, COUNT(*) as orders_count, SUM(amount) as total_amount, SUM(converted_amount) as total_converted_amount')
            ->get();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create-manual' => Pages\CreateManualOrder::route('/create-manual'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    /**
     * 手工建单和 API 建单是唯一两条订单来源，后台不提供"直接新建订单"的
     * 标准 CRUD 入口（那样会绕开金额校验 / 汇率快照 / 风控）——这个方法
     * 控制的是 Filament 默认 CreateRecord 路由，我们的手工建单走独立的
     * create-manual 路由，权限判断见 CreateManualOrder 页面自己的
     * canAccess()（需要 orders.create_manual 权限）。
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * 订单是财务记录，不允许在后台编辑已生成的核心字段（金额、汇率快照等）
     * ——如果需要变更，应该走退款/取消这类业务操作而不是直接改字段。
     * 详情页仍然可以"录入物流"，因为那是独立的 OrderShipping 写入路径
     * （见 ViewOrder 里 recordShipment 动作的 orders.ship 权限判断）。
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::ORDERS_VIEW);
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }
}

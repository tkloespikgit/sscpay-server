<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Events\OrderStatusChanged;
use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\OrderDisputeEventResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\RelationManagers\OrderDisputeEventsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderEventsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderItemsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderMatchedItemsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderNotificationAttemptsRelationManager;
use App\Filament\Support\FinanceSecurity;
use App\Jobs\SyncOrderTrackingJob;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\OrderShipping;
use App\Services\BalanceService;
use App\Services\OrderDisputeService;
use App\Services\OrderEventSyncService;
use App\Services\OrderShippingService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.order.sections.order_info'))->schema([
                Grid::make(3)->schema([
                    TextEntry::make('order_no')->label(__('admin.order.fields.order_no'))->copyable(),
                    TextEntry::make('merchant_order_no')->label(__('admin.order.fields.merchant_order_no')),
                    TextEntry::make('status')->label(__('admin.order.fields.status'))->badge()
                        ->formatStateUsing(fn (string $state) => __('admin.order.statuses.'.$state)),
                    TextEntry::make('source')->label(__('admin.order.fields.source')),
                    TextEntry::make('platform')->label(__('admin.order.fields.platform'))->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('paymentMethod.method_name')->label(__('admin.order.fields.payment_method'))
                        ->formatStateUsing(fn (?string $state, $record) => $state ?? $record->payment_method),
                    TextEntry::make('transaction_id')->label(__('admin.order.fields.transaction_id'))
                        ->copyable()
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('created_at')->label(__('admin.order.fields.created_at'))->dateTime(),
                    // 支付成功时间：网关首次把订单推进到已收款状态族时落的快照
                    // （见 OrderPaymentStatusService::applyStatus()），历史订单为空。
                    TextEntry::make('paid_at')->label(__('admin.order.fields.paid_at'))->dateTime()
                        ->placeholder(__('admin.order.placeholders.none')),
                ]),
            ]),

            Section::make(__('admin.order.sections.amount_info'))->schema([
                Grid::make(4)->schema([
                    TextEntry::make('currency')->label(__('admin.order.fields.currency')),
                    TextEntry::make('amount')->label(__('admin.order.fields.amount'))->money(fn ($record) => $record->currency),
                    TextEntry::make('converted_amount')->label(__('admin.order.fields.converted_amount'))->money('usd'),
                    TextEntry::make('exchange_rate')->label(__('admin.order.fields.exchange_rate')),
                    // 原始汇率 / 汇损百分比 / 汇损费用属于平台内部财务信息，仅超级管理员可见，
                    // 商户用户看到的金额信息到实际汇率为止。
                    TextEntry::make('original_exchange_rate')->label(__('admin.order.fields.original_exchange_rate'))
                        ->visible(fn () => (bool) auth()->user()?->is_super_admin),
                    TextEntry::make('surcharge_percent')->label(__('admin.order.fields.surcharge_percent'))->suffix('%')
                        ->visible(fn () => (bool) auth()->user()?->is_super_admin),
                    TextEntry::make('surcharge_fee')->label(__('admin.order.fields.surcharge_fee'))->money('usd')
                        ->visible(fn () => (bool) auth()->user()?->is_super_admin),
                ]),
            ]),

            Section::make(__('admin.order.sections.customer_info'))->schema([
                Grid::make(2)->schema([
                    TextEntry::make('customer_first_name')->label(__('admin.order.fields.customer_first_name')),
                    TextEntry::make('customer_last_name')->label(__('admin.order.fields.customer_last_name')),
                    TextEntry::make('customer_email')->label(__('admin.order.fields.customer_email')),
                    TextEntry::make('customer_phone')->label(__('admin.order.fields.customer_phone')),
                    TextEntry::make('shipping_address_line1')->label(__('admin.order.fields.address'))->columnSpanFull(),
                    TextEntry::make('shipping_city')->label(__('admin.order.fields.city')),
                    TextEntry::make('shipping_country')->label(__('admin.order.fields.country')),
                ]),
            ]),

            Section::make(__('admin.order.sections.shipping_info'))
                ->schema([
                    TextEntry::make('shipping.logistics_company')->label(__('admin.order.fields.logistics_company'))->placeholder(__('admin.order.placeholders.not_shipped')),
                    TextEntry::make('shipping.tracking_number')->label(__('admin.order.fields.tracking_number'))->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping.shipped_at')->label(__('admin.order.fields.shipped_at'))->dateTime()->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping.sync_status')->label(__('admin.order.fields.sync_status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => $state ? __('admin.order.sync_statuses.'.$state) : null)
                        ->color(fn (?string $state) => match ($state) {
                            OrderShipping::SYNC_STATUS_SYNCED => 'success',
                            OrderShipping::SYNC_STATUS_FAILED => 'danger',
                            default => 'gray',
                        })
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('shipping.sync_message')->label(__('admin.order.fields.sync_message'))
                        ->placeholder(__('admin.order.placeholders.none'))
                        ->columnSpanFull(),
                    TextEntry::make('shipping.operator.name')->label(__('admin.order.fields.operator'))
                        ->state(fn ($record) => $record->shipping?->operator_id === OrderShippingService::API_OPERATOR_ID
                            ? 'API'
                            : $record->shipping?->operator?->name)
                        ->placeholder(__('admin.order.placeholders.none')),
                ])
                ->headerActions([
                    Action::make('syncTrackingNow')
                        ->label(__('admin.order.actions_shipping.sync_now'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        // 只有待同步/同步失败的记录才需要手动重试；已同步的没有必要重复触发。
                        ->visible(fn ($record) => auth()->user()->can(Permissions::ORDERS_SHIP)
                            && $record->shipping
                            && in_array($record->shipping->sync_status, [
                                OrderShipping::SYNC_STATUS_PENDING,
                                OrderShipping::SYNC_STATUS_FAILED,
                            ], true))
                        ->action(function ($record) {
                            // 立即标记为待同步，给出"已在处理中"的即时反馈；真实结果由 Job 执行完后回写。
                            $record->shipping->update(['sync_status' => OrderShipping::SYNC_STATUS_PENDING]);

                            SyncOrderTrackingJob::dispatch($record->shipping->id);

                            Notification::make()
                                ->title(__('admin.order.actions_shipping.sync_now_queued'))
                                ->success()
                                ->send();
                        }),
                    Action::make('recordShipment')
                        ->label(fn ($record) => $record->shipping ? __('admin.order.actions_shipping.update') : __('admin.order.actions_shipping.record'))
                        ->icon('heroicon-o-truck')
                        // 终态订单（已完成/已取消/已退款/已拒付）不允许录入物流，
                        // 直接隐藏按钮，避免用户填完表单提交时才被拒绝。
                        ->visible(fn ($record) => auth()->user()->can(Permissions::ORDERS_SHIP)
                            && in_array($record->status, OrderShippingService::RECORDABLE_STATUSES, true))
                        ->fillForm(fn ($record) => $record->shipping?->only(['logistics_company', 'tracking_number', 'tracking_url', 'remark']) ?? [])
                        ->schema([
                            // 下拉选自 CarrierResource 维护的承运商清单，而不是自由文本——
                            // 保证手动录入的 logistics_company 和 API/CSV 两个入口一样，
                            // 只能是系统认识的 carrier_code（见 Carrier::isValidCode()）。
                            Select::make('logistics_company')
                                ->label(__('admin.order.fields.logistics_company'))
                                ->required()
                                ->searchable()
                                // preload + options() 保证下拉框一打开就直接展示承运商列表，
                                // 不用先输入关键字才触发 getSearchResultsUsing 的异步搜索。
                                ->preload()
                                ->options(fn () => static::carrierOptions())
                                ->getSearchResultsUsing(fn (string $search) => static::carrierOptions($search))
                                ->getOptionLabelUsing(function (?string $value) {
                                    if (blank($value)) {
                                        return null;
                                    }

                                    $carrier = Carrier::query()->where('carrier_code', $value)->first();

                                    return $carrier ? "{$carrier->carrier_name} ({$carrier->carrier_code})" : $value;
                                }),
                            TextInput::make('tracking_number')->label(__('admin.order.fields.tracking_number'))->required()->maxLength(100),
                            TextInput::make('tracking_url')->label(__('admin.order.fields.tracking_url'))->url()->maxLength(255),
                            DateTimePicker::make('shipped_at')->label(__('admin.order.fields.shipped_at'))->default(now()),
                            Textarea::make('remark')->label(__('admin.order.fields.remark'))->rows(2),
                        ])
                        ->action(function (array $data, OrderShippingService $service) {
                            $service->record($this->record, auth()->id(), $data);

                            // 待支付/部分退款的订单录入物流后状态不会被推进，
                            // 成功文案不能谎报"已更新为已发货"。
                            $statusAdvanced = $this->record->refresh()->status === 'shipped';

                            Notification::make()
                                ->title(__($statusAdvanced ? 'admin.order.actions_shipping.success' : 'admin.order.actions_shipping.success_saved'))
                                ->success()
                                ->send();

                            // 成功后硬跳转（重新加载）当前详情页：弹框随之关闭，
                            // 页面上的物流信息区块也保证拿到最新数据。
                            $this->redirect(static::getUrl(['record' => $this->record]));
                        }),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            OrderResource::queryStatusAction(),
            $this->syncOrderEventsAction(),
            $this->openDisputeAction(),
            $this->viewActiveDisputeEventAction(),
            $this->refundAction(),
            $this->chargebackAction(),
            $this->manualStatusChangeAction(),
        ];
    }

    /**
     * 超级管理员专用兜底：当自动状态流转（网关回调 / 查询最新状态）因为异常
     * 没能生效时，人工把订单强制改到「交易成功」或「失败」。
     *
     * 不复用 OrderPaymentStatusService::applyStatus()——那条路径带着"已收款
     * 状态不允许回退""终态不接受覆盖"这些为网关事件设计的保护规则，人工纠错
     * 恰恰是要绕开这些规则；这里只做最小化校验（来源状态是否合理）+ 直接落库
     * + 照样 fire OrderStatusChanged（下游 Telegram 监听器已经兼容非网关来源）。
     *
     * 「拒付」不在这里：已有 chargebackAction 走 BalanceService::chargeback()
     * 真实扣款 + 2FA，语义和这个"纯改状态标签"的动作不一样，不重复实现。
     * completed/failed 本身不涉及金额变动，所以不需要 2FA。
     */
    private function manualStatusChangeAction(): Action
    {
        // 目标状态 => 允许的来源状态（业务含义：completed 只能从"已收过款"的
        // 状态族确认完成；failed 只能从"还没收到钱"的 pending 标记为失败，
        // 已收款订单不允许被标记为失败，避免和真实到账的钱对不上）。
        $allowedSources = [
            'completed' => ['paid', 'shipped', 'partially_refunded'],
            'failed' => ['pending'],
        ];

        return Action::make('manualStatusChange')
            ->label(__('admin.order.actions.manual_status_change'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn ($record) => (bool) auth()->user()?->is_super_admin
                && collect($allowedSources)->contains(fn ($sources) => in_array($record->status, $sources, true)))
            ->modalDescription(__('admin.order.actions.manual_status_change_desc'))
            ->schema(fn ($record) => [
                Select::make('target_status')
                    ->label(__('admin.order.fields.manual_status_target'))
                    ->options(collect($allowedSources)
                        ->filter(fn ($sources) => in_array($record->status, $sources, true))
                        ->keys()
                        ->mapWithKeys(fn (string $status) => [$status => __('admin.order.statuses.'.$status)]))
                    ->required(),
                Textarea::make('reason')->label(__('admin.order.fields.manual_status_reason'))->rows(2)->maxLength(500),
            ])
            ->action(function (array $data) use ($allowedSources) {
                $order = $this->record;
                $target = $data['target_status'];

                if (! in_array($order->status, $allowedSources[$target] ?? [], true)) {
                    Notification::make()->title(__('admin.order.actions.manual_status_change_invalid'))->danger()->send();

                    return;
                }

                $oldStatus = $order->status;
                $operator = auth()->user();

                $note = now()->toDateTimeString()." [{$operator->name}] 手动改状态 {$oldStatus} → {$target}"
                    .(filled($data['reason'] ?? null) ? "：{$data['reason']}" : '');

                $order->forceFill([
                    'status' => $target,
                    'remark' => trim(($order->remark ? $order->remark."\n" : '').$note),
                ])->save();

                event(new OrderStatusChanged($order, $oldStatus, $target));

                Notification::make()->title(__('admin.order.actions.manual_status_change_success'))->success()->send();
                $this->record->refresh();
            });
    }

    /**
     * 手动同步订单事件（order_events 时间线）：立刻调用插件 POST /order-logs
     * 拉取这笔订单在插件侧的完整日志并幂等落库，效果等同于定时任务
     * SyncOrderEvents 跑到这一笔订单，用于「时间线没跟上 / 想马上看到最新事件」
     * 的排查场景，不用等下一轮调度。
     *
     * 复用 OrderEventSyncService::syncOrderNow()，不另写一份请求/落库逻辑；
     * 该方法内部已经捕获 PaymentGatewayException（计入 orders_failed 而不抛出），
     * 所以这里只需要按返回的 stats 分派通知。
     *
     * 与「查询订单」一样属于只读拉取动作：只归档日志，不改订单状态、不触发入账
     * （状态流转由 payment_status webhook 驱动），因此不需要 2FA。
     */
    private function syncOrderEventsAction(): Action
    {
        return Action::make('syncOrderEvents')
            ->label(__('admin.order.actions.sync_events'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('gray')
            ->visible(fn () => (bool) auth()->user()?->can(Permissions::ORDER_EVENTS_VIEW))
            ->action(function (OrderEventSyncService $service) {
                $stats = $service->syncOrderNow($this->record);

                if ($stats['orders_skipped_no_credentials'] > 0) {
                    Notification::make()
                        ->title(__('admin.order.actions.sync_events_no_credentials'))
                        ->warning()
                        ->send();

                    return;
                }

                if ($stats['orders_failed'] > 0) {
                    Notification::make()
                        ->title(__('admin.order.actions.sync_events_failed'))
                        ->danger()
                        ->send();

                    return;
                }

                if ($stats['logs_fetched'] === 0) {
                    Notification::make()
                        ->title(__('admin.order.actions.sync_events_empty'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.order.actions.sync_events_success', [
                        'written' => $stats['logs_written'],
                        'skipped' => $stats['logs_skipped'],
                    ]))
                    ->success()
                    ->send();

                // 成功后硬跳转刷新详情页：事件时间线是 RelationManager（独立的
                // Livewire 子组件），只刷新当前页面组件不会重新拉取子组件数据。
                $this->redirect(static::getUrl(['record' => $this->record]));
            });
    }

    /**
     * 开立争议审核事件（仅超级管理员/商户财务管理员）：冻结订单金额，
     * 订单状态改为 dispute_review。需要资金操作强制 2FA（同 refund/chargeback）。
     * 只有已付款且当前没有处理中事件的订单才可见——前置条件在
     * BalanceService::freezeForDisputeEvent() 里还会再校验一遍，这里只是
     * 提前隐藏按钮，避免用户填完表单才被拒绝。
     */
    private function openDisputeAction(): Action
    {
        return Action::make('openDisputeEvent')
            ->label(__('admin.order_dispute_event.actions.open'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->visible(fn (Order $record) => auth()->user()->can(Permissions::ORDER_DISPUTES_OPEN)
                && $record->status === 'paid'
                && ! $record->activeDisputeEvent)
            ->schema([
                TextInput::make('event_no')
                    ->label(__('admin.order_dispute_event.fields.event_no'))
                    ->required()
                    ->maxLength(64),
                RichEditor::make('reason')
                    ->label(__('admin.order_dispute_event.fields.reason'))
                    ->required(),
                FileUpload::make('images')
                    ->label(__('admin.order_dispute_event.fields.images'))
                    ->image()
                    ->multiple()
                    ->maxFiles(10)
                    ->disk('local')
                    ->directory('dispute-events-tmp'),
                Select::make('final_action')
                    ->label(__('admin.order_dispute_event.fields.final_action'))
                    ->options([
                        OrderDisputeEvent::FINAL_ACTION_REFUND => __('admin.order_dispute_event.final_actions.refund'),
                        OrderDisputeEvent::FINAL_ACTION_CHARGEBACK => __('admin.order_dispute_event.final_actions.chargeback'),
                    ])
                    ->required(),
                Grid::make(2)->schema([
                    TextInput::make('deadline_value')
                        ->label(__('admin.order_dispute_event.fields.deadline_value'))
                        ->numeric()
                        ->required()
                        ->minValue(1),
                    Select::make('deadline_unit')
                        ->label(__('admin.order_dispute_event.fields.deadline_unit'))
                        ->options([
                            OrderDisputeEvent::DEADLINE_UNIT_HOURS => __('admin.order_dispute_event.deadline_units.hours'),
                            OrderDisputeEvent::DEADLINE_UNIT_DAYS => __('admin.order_dispute_event.deadline_units.days'),
                        ])
                        ->default(OrderDisputeEvent::DEADLINE_UNIT_HOURS)
                        ->required(),
                ]),
                FinanceSecurity::codeField(),
            ])
            ->action(function (array $data, OrderDisputeService $service) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    $event = $service->open($this->record, $user, $data);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.order_dispute_event.notifications.opened'))->success()->send();
                $this->redirect(OrderDisputeEventResource::getUrl('view', ['record' => $event]));
            });
    }

    /**
     * 有处理中事件时的快捷入口，直接跳转到事件详情页。
     */
    private function viewActiveDisputeEventAction(): Action
    {
        return Action::make('viewActiveDisputeEvent')
            ->label(__('admin.order_dispute_event.actions.view_active'))
            ->icon('heroicon-o-eye')
            ->visible(fn (Order $record) => auth()->user()->can(Permissions::ORDER_DISPUTES_VIEW) && $record->activeDisputeEvent)
            ->url(fn (Order $record) => OrderDisputeEventResource::getUrl('view', ['record' => $record->activeDisputeEvent]));
    }

    /**
     * 退款（支持部分退款）。金额按订单原币种输入，封顶为剩余可退金额；
     * 需 orders.refund 权限 + 资金操作强制 2FA。
     */
    private function refundAction(): Action
    {
        return Action::make('refund')
            ->label(__('admin.finance.refund.action'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn ($record) => auth()->user()->can(Permissions::ORDERS_REFUND)
                && in_array($record->status, ['paid', 'shipped', 'completed', 'partially_refunded'], true)
                && bccomp((string) $record->refundableAmount(), '0', 2) > 0)
            ->modalHeading(__('admin.finance.refund.action'))
            ->modalDescription(fn ($record) => __('admin.finance.refund.desc', [
                'remaining' => number_format((float) $record->refundableAmount(), 2),
                'currency' => $record->currency,
                'fee' => number_format((float) ($record->paymentMethodConfig()->refund_fee ?? 0), 2),
            ]))
            ->schema(fn ($record) => [
                TextInput::make('amount')
                    ->label(__('admin.finance.refund.amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->maxValue((float) $record->refundableAmount())
                    ->suffix($record->currency),
                Textarea::make('reason')->label(__('admin.finance.refund.reason'))->rows(2)->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    app(BalanceService::class)->refund($this->record, $data['amount'], $user, $data['reason'] ?? null);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.finance.refund.success'))->success()->send();
                $this->record->refresh();
            });
    }

    /**
     * 拒付（只能全额）。扣减订单全额 + 固定拒付手续费；已退款订单不可拒付。
     * 需 orders.chargeback 权限 + 资金操作强制 2FA。
     */
    private function chargebackAction(): Action
    {
        return Action::make('chargeback')
            ->label(__('admin.finance.chargeback.action'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn ($record) => auth()->user()->can(Permissions::ORDERS_CHARGEBACK)
                && in_array($record->status, ['paid', 'shipped', 'completed'], true)
                && bccomp((string) $record->refunded_amount, '0', 2) === 0)
            ->modalHeading(__('admin.finance.chargeback.action'))
            ->modalDescription(fn ($record) => __('admin.finance.chargeback.desc', [
                'amount' => number_format((float) $record->converted_amount, 2),
                'fee' => number_format((float) ($record->paymentMethodConfig()->chargeback_fee ?? 0), 2),
            ]))
            ->schema([
                Textarea::make('reason')->label(__('admin.finance.chargeback.reason'))->rows(2)->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    app(BalanceService::class)->chargeback($this->record, $user, $data['reason'] ?? null);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.finance.chargeback.success'))->success()->send();
                $this->record->refresh();
            });
    }

    public function getRelationManagers(): array
    {
        return [
            OrderItemsRelationManager::class,
            OrderMatchedItemsRelationManager::class,
            OrderEventsRelationManager::class,
            OrderDisputeEventsRelationManager::class,
            OrderNotificationAttemptsRelationManager::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function carrierOptions(?string $search = null): array
    {
        return Carrier::query()
            ->where('status', Carrier::STATUS_ENABLED)
            ->when(filled($search), fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('carrier_name', 'like', "%{$search}%")
                    ->orWhere('carrier_code', 'like', "%{$search}%")))
            ->orderBy('carrier_name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Carrier $carrier) => [$carrier->carrier_code => "{$carrier->carrier_name} ({$carrier->carrier_code})"])
            ->all();
    }
}

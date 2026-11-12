<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Events\OrderStatusChanged;
use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\OrderDisputeEventResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\RelationManagers\AdConversionAttemptsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderDisputeEventsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderEventsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderItemsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderMatchedItemsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderNotificationAttemptsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\OrderShipmentSyncsRelationManager;
use App\Filament\Support\CryptoOrderDisplay;
use App\Filament\Support\FinanceSecurity;
use App\Jobs\SendPaymentLinkJob;
use App\Jobs\SyncOrderTrackingJob;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\OrderSettlement;
use App\Models\OrderShipping;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\LogisticsTracking\Drivers\SeventeenTrack\SeventeenTrackDriver;
use App\Services\LogisticsTracking\LogisticsTrackingSyncService;
use App\Services\MerchantShipmentSyncService;
use App\Services\OrderDisputeService;
use App\Services\OrderEventSyncService;
use App\Services\OrderShippingService;
use App\Support\OrderStatusColor;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.order.sections.order_info'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('order_no')
                            ->label(__('admin.order.fields.order_no'))
                            ->copyable()
                            ->copyableState(fn (?string $state): ?string => $state)
                            ->suffix(fn (Order $record): HtmlString => self::orderStatusSuffix($record)),
                        TextEntry::make('merchant_order_no')->label(__('admin.order.fields.merchant_order_no'))->copyable(),
                        TextEntry::make('wp_order_id')->label(__('admin.order.fields.wp_order_id'))
                            ->copyable()
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('transaction_id')->label(__('admin.order.fields.transaction_id'))
                            ->copyable()
                            ->placeholder(__('admin.order.placeholders.none')),

                        TextEntry::make('platform')->label(__('admin.order.fields.platform'))->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('paymentMethod.method_name')->label(__('admin.order.fields.payment_method'))
                            ->formatStateUsing(fn (?string $state, $record) => $state ?? $record->payment_method),

                        TextEntry::make('created_at')->label(__('admin.order.fields.created_at'))->dateTime(),
                        // 支付成功时间：网关首次把订单推进到已收款状态族时落的快照
                        // （见 OrderPaymentStatusService::applyStatus()），历史订单为空。
                        TextEntry::make('paid_at')->label(__('admin.order.fields.paid_at'))->dateTime()
                            ->placeholder(__('admin.order.placeholders.none')),

                        IconEntry::make('send_mail')
                            ->label(__('admin.order.fields.send_mail'))
                            ->boolean()
                            ->columnSpan(fn (Order $record): int => $this->canResendPaymentLinkMail($record) ? 2 : 1)
                            ->extraEntryWrapperAttributes(['class' => 'order-send-mail-entry'])
                            ->afterContent(fn (Order $record): array => $this->canResendPaymentLinkMail($record)
                                ? [$this->resendPaymentLinkMailAction()->button()]
                                : []),
                        TextEntry::make('payment_link_sent_at')->label(__('admin.order.fields.payment_link_sent_at'))->dateTime()
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('payment_link_mail_failed_reason')
                            ->label(__('admin.order.fields.payment_link_mail_failed_reason'))
                            ->visible(fn ($record) => filled($record->payment_link_mail_failed_reason))
                            ->badge()
                            ->color('danger'),
                    ]),
                ])
                ->columnSpanFull(),

            Section::make(__('admin.order.sections.amount_info'))->schema([
                Grid::make(4)->schema([
                    TextEntry::make('amount')
                        ->label(__('admin.order.fields.original_transaction_amount'))
                        ->money(fn (Order $record) => $record->currency)
                        ->weight(FontWeight::Bold)
                        ->color('primary'),
                    TextEntry::make('settlement_amount')
                        ->label(__('admin.order.fields.settlement_amount'))
                        ->state(fn (Order $record): ?string => $record->payment_type === 'crypto' ? CryptoOrderDisplay::amount($record) : ($record->settlement_amount === null ? null : '$'.number_format((float) $record->settlement_amount, 2)))
                        ->weight(FontWeight::Bold)
                        ->color('success'),
                    TextEntry::make('crypto_denomination')->label(__('crypto.order.denomination'))
                        ->state(fn (Order $record): string => CryptoOrderDisplay::denomination($record))->visible(fn (Order $record): bool => $record->payment_type === 'crypto'),
                    TextEntry::make('crypto_accounting_state')->label(__('crypto.review.accounting_state'))
                        ->state(fn (Order $record): string => CryptoOrderDisplay::state($record))->visible(fn (Order $record): bool => $record->payment_type === 'crypto'),
                    TextEntry::make('settlement_mode')->visible(fn (Order $record): bool => $record->payment_type !== 'crypto')
                        ->label(__('settlement.fields.mode'))
                        ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                            'immediate' => __('admin.order.settlement_modes.immediate'),
                            'shipped' => __('admin.order.settlement_modes.shipped'),
                            default => null,
                        })
                        ->placeholder(__('admin.order.placeholders.none')),
                    TextEntry::make('settlement.status')->visible(fn (Order $record): bool => $record->payment_type !== 'crypto')
                        ->label(__('admin.order.columns.settlement_status'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state): ?string => $state ? __('settlement.statuses.'.$state) : null)
                        ->placeholder(__('settlement.no_record'))
                        ->color(fn (?string $state): string => match ($state) {
                            OrderSettlement::STATUS_SETTLED => 'success',
                            OrderSettlement::STATUS_CANCELLED => 'danger',
                            OrderSettlement::STATUS_PENDING => 'warning',
                            default => 'gray',
                        }),

                    // 折算、汇率、汇损和手续费属于平台财务信息。
                    // 商户只看上面的结算模式、结算状态、原始交易金额和实际到账金额。
                    TextEntry::make('converted_amount')
                        ->label(__('admin.order.fields.converted_amount'))
                        ->formatStateUsing(function ($state): string {
                            $raw = (string) ($state ?? '0');

                            return number_format((float) $raw, 2, '.', ',');
                        })
                        ->prefix('$')
                        ->visible(fn (): bool => self::canSeeAmountBreakdown()),
                    TextEntry::make('exchange_rate')
                        ->label(__('admin.order.fields.exchange_rate'))
                        ->state(fn (Order $record): HtmlString|string => $record->payment_type === 'crypto' ? CryptoOrderDisplay::rate($record) : self::exchangeRateState($record))
                        ->visible(fn (): bool => self::canSeeAmountBreakdown()),
                    TextEntry::make('surcharge_percent')->label(__('admin.order.fields.surcharge_percent'))->suffix('%')
                        ->visible(fn (Order $record): bool => self::canSeeAmountBreakdown() && $record->payment_type !== 'crypto'),
                    TextEntry::make('surcharge_fee')->label(__('admin.order.fields.surcharge_fee'))->money('usd')
                        ->visible(fn (Order $record): bool => self::canSeeAmountBreakdown() && $record->payment_type !== 'crypto'),
                    TextEntry::make('fee_percent_amount')->label(__('admin.order.fields.fee_percent_amount'))->money('usd')
                        ->visible(fn (Order $record): bool => self::canSeeAmountBreakdown() && $record->payment_type !== 'crypto'),
                    TextEntry::make('fee_fixed_amount')->label(__('admin.order.fields.fee_fixed_amount'))->money('usd')
                        ->visible(fn (Order $record): bool => self::canSeeAmountBreakdown() && $record->payment_type !== 'crypto'),
                    TextEntry::make('crypto_fee')->label(__('crypto.order.fee'))->state(fn (Order $record): string => CryptoOrderDisplay::amount($record, 'fee'))
                        ->visible(fn (Order $record): bool => self::canSeeAmountBreakdown() && $record->payment_type === 'crypto'),
                ]),
                Section::make(__('admin.order.sections.settlement_summary'))->visible(fn (Order $record): bool => $record->payment_type !== 'crypto')
                    ->schema([
                        TextEntry::make('settlement_summary')
                            ->hiddenLabel()
                            ->state(fn (Order $record): HtmlString => self::settlementSummaryTable($record)),
                    ]),
            ])->columnSpanFull(),

            Section::make(__('admin.order.sections.customer_shipping'))
                ->schema([
                    self::fulfillmentHeadingEntry('customer_block_heading', __('admin.order.sections.customer')),
                    Grid::make(4)->schema([
                        TextEntry::make('customer_last_name')->label(__('admin.order.fields.customer_last_name'))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('customer_first_name')->label(__('admin.order.fields.customer_first_name'))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('customer_phone')->label(__('admin.order.fields.customer_phone'))
                            ->placeholder(__('admin.order.placeholders.none'))
                            ->copyable(),
                        TextEntry::make('customer_email')->label(__('admin.order.fields.customer_email'))
                            ->placeholder(__('admin.order.placeholders.none'))
                            ->copyable(),
                    ]),
                    self::fulfillmentRuleEntry('customer_block_rule'),
                    self::fulfillmentHeadingEntry('address_block_heading', __('admin.order.sections.shipping_address')),
                    Grid::make(4)->schema([
                        TextEntry::make('shipping_address_line1')->label(__('admin.order.fields.address'))
                            ->placeholder(__('admin.order.placeholders.none'))
                            ->copyable()
                            ->columnSpan(2),
                        TextEntry::make('shipping_address_line2')->label(__('admin.order.fields.address_line2'))
                            ->placeholder(__('admin.order.placeholders.none'))
                            ->columnSpan(2),
                        TextEntry::make('shipping_city')->label(__('admin.order.fields.city'))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('shipping_state')->label(__('admin.order.fields.state'))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('shipping_country')->label(__('admin.order.fields.country'))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('shipping_zip')->label(__('admin.order.fields.zip'))
                            ->placeholder(__('admin.order.placeholders.none')),
                    ]),
                    self::fulfillmentRuleEntry('address_block_rule'),
                    self::fulfillmentHeadingEntry('shipping_block_heading', __('admin.order.sections.shipping_info')),
                    Grid::make(4)->schema([
                        TextEntry::make('shipment_number')
                            ->label(__('admin.order.fields.tracking_number'))
                            ->state(fn (Order $record): HtmlString => self::trackingNumberState($record)),
                        TextEntry::make('shipping.shipped_at')->label(__('admin.order.fields.shipped_at'))
                            ->dateTime()
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('shipping.sync_status')->label(__('admin.order.fields.sync_status'))
                            ->badge()
                            ->formatStateUsing(fn (?string $state): ?string => $state ? __('admin.order.sync_statuses.'.$state) : null)
                            ->color(fn (?string $state): string => match ($state) {
                                OrderShipping::SYNC_STATUS_SYNCED => 'success',
                                OrderShipping::SYNC_STATUS_FAILED => 'danger',
                                default => 'gray',
                            })
                            ->placeholder(__('admin.order.placeholders.none'))
                            ->hintAction(
                                Action::make('viewSyncMessage')
                                    ->icon('heroicon-o-question-mark-circle')
                                    ->label(__('admin.order.fields.sync_message'))
                                    ->visible(fn (Order $record): bool => filled($record->shipping?->sync_message))
                                    ->modalHeading(__('admin.order.fields.sync_message'))
                                    ->modalContent(fn (Order $record): HtmlString => new HtmlString(
                                        '<div class="whitespace-pre-wrap">'.e((string) $record->shipping?->sync_message).'</div>'
                                    ))
                                    ->modalSubmitAction(false)
                                    ->modalCancelAction(fn (Action $action): Action => $action->label(__('admin.order.modals.close')))
                            ),
                        TextEntry::make('shipping_operator')->label(__('admin.order.fields.operator'))
                            ->state(fn (Order $record): ?string => self::shippingOperator($record))
                            ->placeholder(__('admin.order.placeholders.none')),
                    ]),
                    self::fulfillmentHeadingEntry('tracking_block_heading', __('admin.order.sections.tracking_info')),
                    Grid::make(4)->schema([
                        TextEntry::make('tracking_provider_label')->label(__('admin.order.fields.tracking_provider'))
                            ->state(fn (Order $record): ?string => self::trackingProviderLabel($record->shipping?->tracking_provider))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('tracking_status_label')->label(__('admin.order.fields.tracking_status'))
                            ->state(fn (Order $record): ?string => self::trackingStatusLabel($record->shipping))
                            ->placeholder(__('admin.order.placeholders.no_tracking_status')),
                        TextEntry::make('tracking_last_event_label')->label(__('admin.order.fields.tracking_last_event_at'))
                            ->state(fn (Order $record): ?string => self::trackingLastEventLabel($record->shipping))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('tracking_anomaly_label')->label(__('admin.order.fields.logistics_anomaly'))
                            ->state(fn (Order $record): ?string => self::trackingAnomalyLabel($record->shipping))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('tracking_delivery_label')->label(__('admin.order.fields.delivery_status'))
                            ->state(fn (Order $record): ?string => self::trackingDeliveryLabel($record->shipping))
                            ->placeholder(__('admin.order.placeholders.none')),
                    ]),
                    self::fulfillmentHeadingEntry('merchant_sync_block_heading', __('admin.order.sections.merchant_shipment_sync')),
                    Grid::make(4)->schema([
                        TextEntry::make('latestShipmentSync.status')
                            ->label(__('admin.merchant_shipment_sync.fields.status'))
                            ->formatStateUsing(fn (?string $state): ?string => $state ? __('admin.merchant_shipment_sync.statuses.'.$state) : null)
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'success' => 'success',
                                'failed' => 'danger',
                                'retrying', 'processing' => 'warning',
                                default => 'gray',
                            })
                            ->placeholder(__('admin.merchant_shipment_sync.no_records')),
                        TextEntry::make('latestShipmentSync.attempt_count')
                            ->label(__('admin.merchant_shipment_sync.fields.attempt_count'))
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('shipment_returned_at')
                            ->label(__('admin.merchant_shipment_sync.fields.returned_at'))
                            ->state(fn (Order $record) => self::shipmentReturnedAt($record))
                            ->dateTime()
                            ->placeholder(__('admin.order.placeholders.none')),
                        TextEntry::make('latestShipmentSync.last_error')
                            ->label(__('admin.merchant_shipment_sync.fields.last_error'))
                            ->placeholder(__('admin.order.placeholders.none')),
                    ]),
                ])
                ->headerActions([
                    Action::make('syncTrackingNow')
                        ->label(__('admin.order.actions_shipping.sync_now'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->size(Size::ExtraSmall)
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
                    Action::make('pollTrackingNow')
                        ->label(__('admin.order.actions_shipping.poll_tracking'))
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('gray')
                        ->size(Size::ExtraSmall)
                        /*->visible(function (Order $record): bool {
                            if (! auth()->user()?->can(Permissions::ORDERS_SHIP) || ! $record->shipping) {
                                return false;
                            }

                            if (trim((string) $record->shipping->tracking_number) === '') {
                                return false;
                            }

                            return app(LogisticsTrackingSyncService::class)->gatesAllow($record->shipping);
                        })*/
                        ->action(function (Order $record, LogisticsTrackingSyncService $sync): void {
                            $ok = $sync->pollTrackInfo($record->shipping);
                            $this->record->refresh();
                            $this->record->load('shipping');

                            Notification::make()
                                ->title($ok
                                    ? __('admin.order.actions_shipping.poll_tracking_success')
                                    : __('admin.order.actions_shipping.poll_tracking_failed'))
                                ->{$ok ? 'success' : 'warning'}()
                                ->send();
                        }),
                    Action::make('recordShipment')
                        ->label(fn ($record
                        ) => $record->shipping ? __('admin.order.actions_shipping.update') : __('admin.order.actions_shipping.record'))
                        ->icon('heroicon-o-truck')
                        ->size(Size::ExtraSmall)
                        ->authorize(fn (Order $record): bool => $this->canPerformOrderAction($record,
                            Permissions::ORDERS_SHIP))
                        ->visible(fn ($record) => $this->canPerformOrderAction($record, Permissions::ORDERS_SHIP)
                            && in_array($record->status, OrderShippingService::RECORDABLE_STATUSES, true))
                        // shipped_at 必须一起回填：补发/改单时 fillForm() 给了值的表单
                        // 不会再套用字段上的 default()，漏填这一项会把已有的发货时间
                        // 提交成 NULL，而那一列是 NOT NULL（实测报 1048）。
                        ->fillForm(fn ($record) => $record->shipping?->only([
                            'logistics_company', 'tracking_number', 'tracking_url', 'shipped_at', 'remark',
                        ]) ?? [])
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
                            DateTimePicker::make('shipped_at')->label(__('admin.order.fields.shipped_at'))->required()->default(now()),
                            Textarea::make('remark')->label(__('admin.order.fields.remark'))->rows(2),
                        ])
                        ->action(function (array $data, OrderShippingService $service) {
                            $this->authorizeOrderAction(Permissions::ORDERS_SHIP);
                            $this->record->refresh();
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
                    $this->pushMerchantShipmentAction()->size(Size::ExtraSmall),
                ])
                ->columnSpanFull(),
        ]);
    }

    private static function fulfillmentHeadingEntry(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->hiddenLabel()
            ->state(fn (): HtmlString => new HtmlString('<div class="order-fulfillment-heading">'.e($label).'</div>'));
    }

    private static function fulfillmentRuleEntry(string $name): TextEntry
    {
        return TextEntry::make($name)
            ->hiddenLabel()
            ->state(fn (): HtmlString => new HtmlString('<hr class="order-fulfillment-rule">'));
    }

    private static function trackingNumberState(Order $record): HtmlString
    {
        $shipping = $record->shipping;
        $number = trim((string) $shipping?->tracking_number);

        if ($shipping === null || $number === '') {
            return new HtmlString(e(__('admin.order.placeholders.not_shipped')));
        }

        $html = '<strong class="fi-color-primary fi-text-color-600" style="font-weight:700;color:var(--text)">'.e($number).'</strong>';
        $company = self::carrierLabel($shipping->logistics_company);

        if ($company !== '') {
            $html .= ' <a class="order-fulfillment-link" href="'.e(self::trackingQueryUrl($shipping)).'" target="_blank" rel="noopener noreferrer">'.'（'.e($company).'）'.'</a>';

        } else {
            $html .= ' <a class="order-fulfillment-link" href="'.e(self::trackingQueryUrl($shipping)).'" target="_blank" rel="noopener noreferrer">'.'（'.e($company).'）'.'</a>';
        }

        return new HtmlString($html);
    }

    private static function shippingOperator(Order $record): ?string
    {
        $shipping = $record->shipping;

        if ($shipping === null) {
            return null;
        }

        if ($shipping->operator_id === OrderShippingService::API_OPERATOR_ID) {
            return 'API';
        }

        return $shipping->operator?->name;
    }

    private static function trackingProviderLabel(?string $provider): ?string
    {
        $provider = trim((string) $provider);

        if ($provider === '') {
            return null;
        }

        return match ($provider) {
            SeventeenTrackDriver::NAME => '17TRACK',
            default => $provider,
        };
    }

    private static function trackingStatusLabel(?OrderShipping $shipping): ?string
    {
        if ($shipping === null || blank($shipping->tracking_status)) {
            return null;
        }

        $status = OrderResource::formatTrackingStatus($shipping->tracking_status) ?? $shipping->tracking_status;
        $subStatus = OrderResource::formatTrackingSubStatus($shipping->tracking_sub_status);

        return filled($subStatus) ? $status.'（'.$subStatus.'）' : $status;
    }

    private static function trackingLastEventLabel(?OrderShipping $shipping): ?string
    {
        if ($shipping === null) {
            return null;
        }

        $time = $shipping->tracking_last_event_at
            ?->timezone(config('app.timezone'))
            ->format('Y/m/d H:i:s');
        $description = trim((string) $shipping->tracking_last_event_desc);

        if ($time === null && $description === '') {
            return null;
        }

        if ($time !== null && $description !== '') {
            return $time.'（'.$description.'）';
        }

        return $time ?? $description;
    }

    private static function trackingAnomalyLabel(?OrderShipping $shipping): ?string
    {
        if ($shipping === null || $shipping->logistics_anomaly === null) {
            return null;
        }

        if (! $shipping->logistics_anomaly) {
            return __('admin.order.filters.logistics_anomaly_no');
        }

        $details = array_values(array_filter([
            OrderResource::formatLogisticsAnomalyReason($shipping->logistics_anomaly_reason),
            $shipping->logistics_anomaly_at
                ?->timezone(config('app.timezone'))
                ->format('Y/m/d H:i:s'),
        ]));
        $label = __('admin.order.filters.logistics_anomaly_yes');

        return $details === [] ? $label : $label.'（'.implode('，', $details).'）';
    }

    private static function trackingDeliveryLabel(?OrderShipping $shipping): ?string
    {
        if ($shipping === null) {
            return null;
        }

        $delivered = $shipping->tracking_status === 'Delivered' || filled($shipping->tracking_delivered_at);

        if (! $delivered) {
            return __('admin.order.delivery.pending');
        }

        $label = __('admin.order.delivery.delivered');
        $time = $shipping->tracking_delivered_at
            ?->timezone(config('app.timezone'))
            ->format('Y/m/d H:i:s');

        return $time === null ? $label : $label.'（'.$time.'）';
    }

    private static function shipmentReturnedAt(Order $record): mixed
    {
        $sync = $record->latestShipmentSync;

        if ($sync === null) {
            return null;
        }

        if ($sync->synced_at !== null) {
            return $sync->synced_at;
        }

        return $sync->attempts()->reorder()->orderByDesc('attempt_number')->first()?->attempted_at;
    }

    private static function trackingQueryUrl(OrderShipping $shipping): string
    {
        $stored = trim((string) $shipping->tracking_url);

        if (self::isPublicHttpUrl($stored)) {
            return $stored;
        }

        return 'https://t.17track.net/zh-cn#nums='.rawurlencode(trim((string) $shipping->tracking_number));
    }

    private static function isPublicHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    private static function carrierLabel(?string $code): string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return '';
        }

        $name = Carrier::query()
            ->whereRaw('UPPER(carrier_code) = ?', [mb_strtoupper($code)])
            ->value('carrier_name');

        return filled($name) ? (string) $name : $code;
    }

    private static function canSeeAmountBreakdown(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformStaff();
    }

    /**
     * 实际汇率红色、四位小数；原始汇率灰色删除线跟在同一行。
     */
    private static function exchangeRateState(Order $record): HtmlString
    {
        $actual = e(number_format((float) $record->exchange_rate, 4, '.', ''));
        $html = '<span style="white-space:nowrap"><span style="color:#dc2626;font-weight:700">'.$actual.'</span>';

        if (filled($record->original_exchange_rate)) {
            $original = e(number_format((float) $record->original_exchange_rate, 4, '.', ''));
            $html .= ' <span style="color:#9ca3af;text-decoration:line-through">'.$original.'</span>';
        }

        return new HtmlString($html.'</span>');
    }

    /**
     * 一笔订单一行：保证金、结算到可提现、结算时间、保证金释放时间。
     * 已释放时用实际释放时间，否则用计划释放时间。
     */
    private static function settlementSummaryTable(Order $record): HtmlString
    {
        $settlement = $record->settlement;
        $freeze = $record->fundFreezes->sortByDesc('id')->first();
        $releaseAt = $freeze?->released_at ?? $freeze?->release_at;
        $placeholder = e(__('admin.order.placeholders.none'));
        $cells = [
            __('admin.order.settlement_summary.available') => self::usdCell($settlement?->available_amount, $placeholder),
            __('admin.order.settlement_summary.settled_at') => filled($settlement?->settled_at)
                ? e($settlement->settled_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'))
                : $placeholder,

            __('admin.order.settlement_summary.reserve') => self::usdCell($settlement?->reserve_amount, $placeholder),
            __('admin.order.settlement_summary.release_at') => filled($releaseAt)
                ? e($releaseAt->timezone(config('app.timezone'))->format('Y-m-d H:i:s'))
                : $placeholder,
        ];
        $headers = '';
        $values = '';

        foreach ($cells as $label => $value) {
            $headers .= '<th style="text-align:left;padding:0.5rem 0.75rem;font-weight:500;color:#6b7280;border-bottom:1px solid #e5e7eb">'.e($label).'</th>';
            $values .= '<td style="padding:0.75rem;font-weight:600">'.$value.'</td>';
        }

        return new HtmlString(
            '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:0.875rem"><thead><tr>'.$headers.'</tr></thead><tbody><tr>'.$values.'</tr></tbody></table></div>'
        );
    }

    private static function usdCell(mixed $amount, string $placeholder): string
    {
        if (! filled($amount)) {
            return $placeholder;
        }

        return e('$'.number_format((float) $amount, 2, '.', ','));
    }

    /**
     * 紧跟在系统订单号后面，括号、粗体，并用状态色着重。
     */
    private static function orderStatusSuffix(Order $record): HtmlString
    {
        $color = OrderStatusColor::get((string) $record->status);
        $label = e(__('admin.order.statuses.'.$record->status));

        return new HtmlString(
            ' <strong class="fi-color-'.$color.' fi-text-color-600" style="font-weight:700;color:var(--text)">（'.$label.'）</strong>'
        );
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

    private function canPushMerchantShipment(Order $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->status
            || ! $user->can(Permissions::ORDERS_VIEW)
            || ! $user->can(Permissions::ORDERS_SHIP)) {
            return false;
        }

        $merchantIds = $user->manageableMerchantIds();

        return ($merchantIds === null || in_array((int) $record->merchant_id, $merchantIds, true))
            && app(MerchantShipmentSyncService::class)->canPush($record);
    }

    private function pushMerchantShipmentAction(): Action
    {
        return Action::make('pushMerchantShipment')
            ->label(__('admin.merchant_shipment_sync.push'))
            ->icon(Heroicon::ArrowPath)
            ->color('gray')
            ->visible(fn (Order $record): bool => $this->canPushMerchantShipment($record))
            ->authorize(fn (Order $record): bool => $this->canPushMerchantShipment($record))
            ->action(function (Order $record, MerchantShipmentSyncService $service): void {
                abort_unless($this->canPushMerchantShipment($record), 403);

                $service->pushManually($record);
                $this->record->refresh();

                Notification::make()
                    ->title(__('admin.merchant_shipment_sync.queued'))
                    ->success()
                    ->send();
            });
    }

    private function copyPaymentLinkAction(): Action
    {
        return Action::make('copyPaymentLink')
            ->label(__('admin.order.actions.copy_payment_link'))
            ->icon(Heroicon::ClipboardDocument)
            ->color('gray')
            ->visible(fn (Order $record): bool => filled($record->pay_url))
            ->alpineClickHandler(function (Order $record): string {
                $payUrl = Js::from($record->pay_url);
                $successMessage = Js::from(__('admin.order.actions.payment_link_copied'));
                $failureMessage = Js::from(__('admin.order.actions.payment_link_copy_failed'));

                return <<<JS
                    (async () => {
                        try {
                            await window.navigator.clipboard.writeText({$payUrl});
                            new FilamentNotification().title({$successMessage}).success().send();
                        } catch {
                            new FilamentNotification().title({$failureMessage}).danger().send();
                        }
                    })()
                    JS;
            });
    }

    /**
     * 未收款订单的人工失败兜底；完成状态只由真实结算结果产生。
     */
    private function manualStatusChangeAction(): Action
    {
        return Action::make('manualStatusChange')
            ->label(__('admin.order.actions.manual_status_change'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('gray')
            ->requiresConfirmation()
            ->authorize(fn (Order $record): bool => $this->canPerformOrderAction($record, Permissions::ORDERS_VIEW)
                && (bool) auth()->user()?->is_super_admin)
            ->visible(fn (Order $record): bool => $this->canPerformOrderAction($record, Permissions::ORDERS_VIEW)
                && (bool) auth()->user()?->is_super_admin && $record->status === 'pending')
            ->modalDescription(__('admin.order.actions.manual_status_change_desc'))
            ->schema([
                Select::make('target_status')
                    ->label(__('admin.order.fields.manual_status_target'))
                    ->options(['failed' => __('admin.order.statuses.failed')])
                    ->required(),
                Textarea::make('reason')->label(__('admin.order.fields.manual_status_reason'))->rows(2)->maxLength(500),
            ])
            ->action(function (array $data): void {
                $operator = $this->authorizeOrderAction(Permissions::ORDERS_VIEW);
                abort_unless($operator->is_super_admin, 403);

                $changed = DB::transaction(function () use ($data, $operator): bool {
                    $order = Order::query()->withoutGlobalScopes()->whereKey($this->record->id)->lockForUpdate()->firstOrFail();
                    if ($order->status !== 'pending' || ($data['target_status'] ?? null) !== 'failed') {
                        return false;
                    }

                    $note = now()->toDateTimeString()." [{$operator->name}] 手动改状态 pending → failed"
                        .(filled($data['reason'] ?? null) ? "：{$data['reason']}" : '');
                    $order->forceFill([
                        'status' => 'failed',
                        'failed_at' => $order->failed_at ?? now(),
                        'remark' => trim(($order->remark ? $order->remark."\n" : '').$note),
                    ])->save();
                    DB::afterCommit(fn () => event(new OrderStatusChanged($order, 'pending', 'failed')));

                    return true;
                });

                if (! $changed) {
                    Notification::make()->title(__('admin.order.actions.manual_status_change_invalid'))->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.order.actions.manual_status_change_success'))->success()->send();
                $this->record->refresh();
            });
    }

    private function canPerformOrderAction(Order $record, string $permission): bool
    {
        return OrderResource::canPerformOrderAction($record, $permission);
    }

    private function authorizeOrderAction(string $permission): User
    {
        abort_unless($this->canPerformOrderAction($this->record, $permission), 403);

        return auth()->user();
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
     * 此动作只归档日志，不改订单状态、不触发入账，因此不需要 2FA。
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
     * 下单时请求过发信、已经记下客户邮箱，并且当前用户能看订单详情时，
     * 才在「下单时请求发送邮件」后面放重发按钮。
     */
    private function canResendPaymentLinkMail(Order $record): bool
    {
        return (bool) auth()->user()?->can(Permissions::ORDERS_VIEW)
            && $record->send_mail
            && filled($record->customer_email);
    }

    /**
     * 立即重发付款链接邮件。按钮跟在「下单时请求发送邮件」后面。只读操作
     * （不改订单状态、不涉及金额），复用 SendPaymentLinkJob，权限对齐
     * 「能看订单详情就能重发」，不需要 2FA。
     */
    private function resendPaymentLinkMailAction(): Action
    {
        return Action::make('resendPaymentLinkMail')
            ->label(__('admin.order.actions.resend_payment_link_mail'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (Order $record): bool => $this->canResendPaymentLinkMail($record))
            ->action(function (Order $record) {
                SendPaymentLinkJob::dispatch($record->id);

                Notification::make()
                    ->title(__('admin.order.actions.resend_payment_link_mail_queued'))
                    ->success()
                    ->send();
            });
    }

    /**
     * 开立争议审核事件（仅超级管理员/商户财务管理员）：冻结订单金额，
     * 订单状态改为 dispute_review。需要资金操作强制 2FA（同 refund/chargeback）。
     * 已收款且当前没有处理中事件的订单才可见——前置条件在
     * BalanceService::freezeForDisputeEvent() 里还会再校验一遍，这里只是
     * 提前隐藏按钮，避免用户填完表单才被拒绝。
     */
    private function openDisputeAction(): Action
    {
        return Action::make('openDisputeEvent')
            ->label(__('admin.order_dispute_event.actions.open'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->authorize(fn (Order $record): bool => $this->canPerformOrderAction($record,
                Permissions::ORDER_DISPUTES_OPEN))
            ->visible(fn (Order $record) => $this->canPerformOrderAction($record, Permissions::ORDER_DISPUTES_OPEN)
                && in_array($record->status,
                    ['paid', 'pending_settlement', 'shipped', 'completed', 'partially_refunded', 'disputing'], true)
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
                    $user = $this->authorizeOrderAction(Permissions::ORDER_DISPUTES_OPEN);
                    $this->record->refresh();
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
            ->visible(fn (Order $record
            ) => auth()->user()->can(Permissions::ORDER_DISPUTES_VIEW) && $record->activeDisputeEvent)
            ->url(fn (Order $record) => OrderDisputeEventResource::getUrl('view',
                ['record' => $record->activeDisputeEvent]));
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
            // status=refunded 也放行：那是网关标记了退款但钱还没扣的历史订单，
            // 必须还能补录把账扣平（放行条件与 BalanceService::assertRefundable() 一致）。
            ->authorize(fn (Order $record): bool => $this->canPerformOrderAction($record, Permissions::ORDERS_REFUND))
            ->visible(fn ($record) => $this->canPerformOrderAction($record, Permissions::ORDERS_REFUND)
                && in_array($record->status,
                    ['paid', 'pending_settlement', 'shipped', 'completed', 'partially_refunded', 'refunded'], true)
                && bccomp((string) $record->refundableAmount(), '0', 2) > 0
                && ! BalanceService::hasChargebackLedger($record))
            ->modalHeading(__('admin.finance.refund.action'))
            ->modalDescription(fn ($record) => __('admin.finance.refund.desc', [
                'remaining' => number_format((float) $record->refundableAmount(), 2),
                'currency' => $record->currency,
                'fee' => number_format((float) ($record->paymentMethodConfig()->refund_fee ?? 0), 2),
            ]).($record->status === 'refunded' ? "\n\n".__('admin.finance.refund.settle_notice') : ''))
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
                    $user = $this->authorizeOrderAction(Permissions::ORDERS_REFUND);
                    $this->record->refresh();
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
            // status=chargeback 且没落过拒付流水的也放行：网关标记了拒付但钱还没扣的
            // 历史订单需要补录（放行条件与 BalanceService::assertChargebackable() 一致）。
            ->authorize(fn (Order $record): bool => $this->canPerformOrderAction($record,
                Permissions::ORDERS_CHARGEBACK))
            ->visible(fn ($record) => $this->canPerformOrderAction($record, Permissions::ORDERS_CHARGEBACK)
                && bccomp((string) $record->refunded_amount, '0', 2) === 0
                && ! BalanceService::hasChargebackLedger($record)
                && (in_array($record->status, ['paid', 'pending_settlement', 'shipped', 'completed'], true)
                    || ($record->status === 'chargeback' && ! BalanceService::hasChargebackLedger($record))))
            ->modalHeading(__('admin.finance.chargeback.action'))
            ->modalDescription(fn ($record) => __('admin.finance.chargeback.desc', [
                'amount' => number_format((float) $record->converted_amount, 2),
                'fee' => number_format((float) ($record->paymentMethodConfig()->chargeback_fee ?? 0), 2),
            ]).($record->status === 'chargeback' ? "\n\n".__('admin.finance.chargeback.settle_notice') : ''))
            ->schema([
                Textarea::make('reason')->label(__('admin.finance.chargeback.reason'))->rows(2)->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (array $data) {
                try {
                    $user = $this->authorizeOrderAction(Permissions::ORDERS_CHARGEBACK);
                    $this->record->refresh();
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
            AdConversionAttemptsRelationManager::class,
            OrderShipmentSyncsRelationManager::class,
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
            ->mapWithKeys(fn (Carrier $carrier
            ) => [$carrier->carrier_code => "{$carrier->carrier_name} ({$carrier->carrier_code})"])
            ->all();
    }
}

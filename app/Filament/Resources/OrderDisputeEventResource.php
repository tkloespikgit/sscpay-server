<?php

namespace App\Filament\Resources;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\OrderDisputeEventResource\Pages;
use App\Filament\Support\FinanceSecurity;
use App\Models\OrderDisputeEvent;
use App\Models\PaymentMethod;
use App\Services\OrderDisputeService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 争议审核事件（人工发起，与网关 webhook 驱动的 disputing 是两套独立机制，
 * 见 OrderPaymentStatusService::shouldApply() 里的守卫）。开立/回复只能从
 * 订单详情页发起（有前置条件校验，不适合通用建表单），这里只提供
 * 独立的列表搜索页 + 详情页（回复线程 + 结束动作）。
 */
class OrderDisputeEventResource extends Resource
{
    protected static ?string $model = OrderDisputeEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.order_management');
    }

    public static function getModelLabel(): string
    {
        return __('admin.order_dispute_event.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.order_dispute_event.model_label_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.order_dispute_event.nav_label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_no')->label(__('admin.order_dispute_event.fields.order_no'))->searchable()->copyable(),
                TextColumn::make('event_no')->label(__('admin.order_dispute_event.fields.event_no'))->searchable()->copyable(),
                TextColumn::make('status')->label(__('admin.order_dispute_event.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.order_dispute_event.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        OrderDisputeEvent::STATUS_PROCESSING => 'warning',
                        OrderDisputeEvent::STATUS_CLOSED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('payment_method')->label(__('admin.order_dispute_event.fields.payment_method'))
                    ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                TextColumn::make('final_action')->label(__('admin.order_dispute_event.fields.final_action'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.order_dispute_event.final_actions.'.$state)),
                TextColumn::make('frozen_amount')->label(__('admin.order_dispute_event.fields.frozen_amount'))->money('usd'),
                TextColumn::make('due_at')->label(__('admin.order_dispute_event.fields.due_at'))->dateTime(),
                TextColumn::make('openedBy.name')->label(__('admin.order_dispute_event.fields.opened_by'))
                    ->placeholder(__('admin.order_dispute_event.placeholders.none')),
                TextColumn::make('opened_at')->label(__('admin.order_dispute_event.fields.opened_at'))->dateTime()->sortable(),
                TextColumn::make('closed_at')->label(__('admin.order_dispute_event.fields.closed_at'))->dateTime()
                    ->placeholder(__('admin.order_dispute_event.placeholders.none')),
            ])
            ->filters([
                Filter::make('order_no')
                    ->label(__('admin.order_dispute_event.filters.order_no'))
                    ->schema([
                        TextInput::make('order_no')
                            ->label(__('admin.order_dispute_event.filters.order_no'))
                            ->placeholder(__('admin.order_dispute_event.filters.order_no_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['order_no'] ?? null,
                        fn ($q, $orderNo) => $q->where('order_no', 'like', '%'.$orderNo.'%')
                    )),

                Filter::make('event_no')
                    ->label(__('admin.order_dispute_event.filters.event_no'))
                    ->schema([
                        TextInput::make('event_no')
                            ->label(__('admin.order_dispute_event.filters.event_no'))
                            ->placeholder(__('admin.order_dispute_event.filters.event_no_placeholder')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['event_no'] ?? null,
                        fn ($q, $eventNo) => $q->where('event_no', 'like', '%'.$eventNo.'%')
                    )),

                SelectFilter::make('payment_method')
                    ->label(__('admin.order_dispute_event.filters.payment_method'))
                    ->options(function () {
                        $user = auth()->user();

                        if (! $user?->is_super_admin) {
                            return PaymentMethod::query()->orderBy('sort_order')->pluck('method_name', 'method_code');
                        }

                        return PaymentMethod::query()
                            ->with('merchant')
                            ->orderBy('sort_order')
                            ->get()
                            ->groupBy(fn (PaymentMethod $method) => $method->merchant?->name ?? '-')
                            ->map(fn ($methods) => $methods->pluck('method_name', 'method_code'))
                            ->toArray();
                    }),

                SelectFilter::make('status')->label(__('admin.order_dispute_event.filters.status'))->options([
                    OrderDisputeEvent::STATUS_PROCESSING => __('admin.order_dispute_event.statuses.processing'),
                    OrderDisputeEvent::STATUS_CLOSED => __('admin.order_dispute_event.statuses.closed'),
                ]),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->recordActions([
                ViewAction::make(),
                static::closeAction(),
            ])
            ->defaultSort('opened_at', 'desc');
    }

    /**
     * 结束事件：列表行内和详情页头部共用（同 OrderResource::queryStatusAction()
     * 的"共用一份定义避免两处各写一遍"思路）。返回 false（已被自动扫描抢先
     * 关闭）时给出提示而不是当成报错处理。
     */
    public static function closeAction(): Action
    {
        return Action::make('closeDisputeEvent')
            ->label(__('admin.order_dispute_event.actions.close'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (OrderDisputeEvent $record) => $record->isProcessing() && auth()->user()->can(Permissions::ORDER_DISPUTES_CLOSE))
            ->modalHeading(__('admin.order_dispute_event.actions.close_heading'))
            ->modalDescription(__('admin.order_dispute_event.actions.close_desc'))
            ->schema([
                Textarea::make('close_remark')->label(__('admin.order_dispute_event.fields.close_remark'))->rows(2)->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (OrderDisputeEvent $record, array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    $closed = app(OrderDisputeService::class)->closeManually($record, $user, $data['close_remark'] ?? null);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($closed
                        ? __('admin.order_dispute_event.notifications.closed')
                        : __('admin.order_dispute_event.notifications.already_closed'))
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderDisputeEvents::route('/'),
            'view' => Pages\ViewOrderDisputeEvent::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // 开立只能从订单详情页发起（前置条件校验 + 资金冻结，不是通用建表单能替代的）。
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

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::ORDER_DISPUTES_VIEW);
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }
}

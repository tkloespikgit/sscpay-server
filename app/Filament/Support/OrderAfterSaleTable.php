<?php

namespace App\Filament\Support;

use App\Filament\Resources\OrderResource;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\OrderAfterSaleService;
use App\Support\OrderStatusColor;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

final class OrderAfterSaleTable
{
    public static function configure(Table $table, bool $chargebacks): Table
    {
        $timeColumn = $chargebacks ? 'after_sale_chargeback_at' : 'after_sale_refunded_at';
        $timeLabel = __('admin.order.after_sale.'.($chargebacks ? 'chargeback_at' : 'refunded_at'));

        return $table
            ->modifyQueryUsing(fn (Builder $query, OrderAfterSaleService $afterSales): Builder => $afterSales->withSummary($query)
                ->with(['merchant', 'application', 'paymentMethod']))
            ->columns([
                TextColumn::make('merchant.name')->label(__('admin.order.columns.merchant_name'))
                    ->visible(fn (): bool => (bool) auth()->user()?->isPlatformStaff())->searchable(),
                TextColumn::make('application.name')->label(__('admin.order.columns.application'))
                    ->searchable(['app_id', 'name']),
                TextColumn::make('order_no')->label(__('admin.order.columns.order_no'))->searchable()->copyable(),
                TextColumn::make('merchant_order_no')->label(__('admin.order.columns.merchant_order_no'))->searchable(),
                TextColumn::make('transaction_id')->label(__('admin.order.columns.transaction_id'))
                    ->searchable()->copyable()->placeholder('—')->toggleable(),
                TextColumn::make('status')->label(__('admin.order.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.order.statuses.'.$state))
                    ->color(fn (string $state): string => OrderStatusColor::get($state)),
                TextColumn::make('after_sale_origin')->label(__('admin.order.after_sale.origin'))
                    ->state(fn (Order $record): string => OrderAfterSaleDisplay::origin($record))
                    ->badge()->color('danger')->visible($chargebacks),
                TextColumn::make('paymentMethod.method_name')->label(__('admin.order.columns.payment_method'))
                    ->state(fn (Order $record): string => (auth()->user()?->isPlatformStaff()
                        ? $record->paymentMethod?->method_name : $record->paymentMethod?->merchantDisplayName()) ?? $record->payment_method),
                TextColumn::make('amount')->label(__('admin.order.columns.amount_original'))
                    ->formatStateUsing(fn (mixed $state, Order $record): string|HtmlString => OrderResource::originalCurrencyAmountState($record, $state))
                    ->html()->alignCenter()->sortable(),
                TextColumn::make('after_sale_refunded_total')->label(__('admin.order.fields.refunded_amount'))
                    ->state(fn (Order $record): string => (string) ($record->after_sale_refunded_total ?? $record->refunded_amount))
                    ->money(fn (Order $record): string => $record->currency),
                TextColumn::make('after_sale_refund_fee_total')->label(__('admin.order.after_sale.refund_fee'))
                    ->money('USD')->placeholder(__('admin.order.after_sale.not_recorded'))
                    ->visible(! $chargebacks),
                TextColumn::make('after_sale_chargeback_fee')->label(__('admin.order.after_sale.chargeback_fee'))
                    ->state(fn (Order $record): string => OrderAfterSaleDisplay::chargebackFee($record))
                    ->description(fn (Order $record): string => OrderAfterSaleDisplay::chargebackFeeStatus($record))
                    ->visible($chargebacks),
                TextColumn::make($timeColumn)->label($timeLabel)->dateTime('Y-m-d H:i:s')
                    ->timezone(config('app.timezone'))->placeholder(__('admin.order.after_sale.not_recorded'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy($timeColumn, $direction)),
                TextColumn::make('created_at')->label(__('admin.order.columns.created_at'))->dateTime()
                    ->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('merchant_id')->label(__('admin.order.filters.merchant'))
                    ->visible(fn (): bool => (bool) auth()->user()?->isPlatformStaff())
                    ->options(function (): array {
                        $ids = auth()->user()?->manageableMerchantIds();

                        return Merchant::query()->when($ids !== null, fn (Builder $query): Builder => $query->whereIn('id', $ids))
                            ->orderBy('name')->pluck('name', 'id')->all();
                    })->searchable(),
                SelectFilter::make('application_id')->label(__('admin.order.filters.application'))
                    ->options(fn (): array => Application::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                SelectFilter::make('payment_method')->label(__('admin.order.filters.payment_method'))
                    ->multiple()->options(function (): array {
                        $user = auth()->user();
                        $query = PaymentMethod::query()->withTrashed();

                        if ($user instanceof User && $user->merchant_id !== null) {
                            $query->forMerchant((int) $user->merchant_id);
                        }

                        return $query->orderBy('sort_order')->get(['method_code', 'method_name', 'display_name'])
                            ->mapWithKeys(fn (PaymentMethod $method): array => [$method->method_code => $user?->isPlatformStaff()
                                ? $method->method_name : $method->merchantDisplayName()])->all();
                    }),
                Filter::make('after_sale_from')->label(__('admin.order.after_sale.time_from', ['event' => $timeLabel]))
                    ->schema([
                        DateTimePicker::make('after_sale_from')->label(__('admin.order.after_sale.time_from', ['event' => $timeLabel]))
                            ->native(false)->seconds()->displayFormat('Y-m-d H:i:s')->timezone(config('app.timezone')),
                    ])
                    ->query(fn (Builder $query, array $data, OrderAfterSaleService $afterSales): Builder => $query->when(
                        filled($data['after_sale_from'] ?? null),
                        fn (Builder $query): Builder => $query->where($afterSales->timeQuery($chargebacks), '>=', $data['after_sale_from']),
                    )),
                Filter::make('after_sale_to')->label(__('admin.order.after_sale.time_to', ['event' => $timeLabel]))
                    ->schema([
                        DateTimePicker::make('after_sale_to')->label(__('admin.order.after_sale.time_to', ['event' => $timeLabel]))
                            ->native(false)->seconds()->displayFormat('Y-m-d H:i:s')->timezone(config('app.timezone')),
                    ])
                    ->query(fn (Builder $query, array $data, OrderAfterSaleService $afterSales): Builder => $query->when(
                        filled($data['after_sale_to'] ?? null),
                        fn (Builder $query): Builder => $query->where($afterSales->timeQuery($chargebacks), '<=', $data['after_sale_to']),
                    )),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3)
            ->recordActions([
                ViewAction::make()->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->defaultSort($timeColumn, 'desc');
    }
}

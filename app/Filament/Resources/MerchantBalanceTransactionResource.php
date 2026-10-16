<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MerchantBalanceTransactionResource\Pages;
use App\Models\MerchantBalanceTransaction;
use App\Support\Permissions;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * 余额流水台账（资金管理），只读。展示总余额增减及各余额分类之间的划转，
 * 包括变动后余额、关联单据与操作人。数据由 BalanceService 写入，后台不提供增删改。
 *
 * 权限：finance.view 可见；人工调整余额入口（列表页头部动作）另需 balance.adjust。
 */
class MerchantBalanceTransactionResource extends Resource
{
    protected static ?string $model = MerchantBalanceTransaction::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.finance');
    }

    public static function getModelLabel(): string
    {
        return __('admin.finance.txn.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.finance.txn.model_label_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.finance.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable()
                    ->visible(fn () => (bool) auth()->user()?->isPlatformStaff()),
                TextColumn::make('type')->label(__('admin.finance.txn.type'))->badge()
                    ->formatStateUsing(fn (string $state) => __('settlement.txn.types.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'order_paid', 'order_settlement', 'reserve_release', 'withdrawal_rejected', 'dispute_release' => 'success',
                        'refund', 'refund_fee', 'chargeback', 'chargeback_fee', 'withdrawal' => 'danger',
                        'manual_adjust', 'reserve_freeze', 'withdrawal_request', 'dispute_freeze' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('amount')->label(__('settlement.txn.total_change'))->money('usd')
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('pending_settlement_change')->label(__('settlement.txn.pending_settlement_change'))->money('usd')->toggleable(),
                TextColumn::make('frozen_change')->label(__('settlement.txn.frozen_change'))->money('usd')->toggleable(),
                TextColumn::make('available_change')->label(__('settlement.txn.available_change'))->money('usd')->toggleable()
                    ->state(function (MerchantBalanceTransaction $record): string {
                        if ($record->type === MerchantBalanceTransaction::TYPE_WITHDRAWAL) {
                            return '0.00';
                        }

                        return bcsub(bcsub(bcsub(
                            (string) $record->amount,
                            (string) $record->pending_settlement_change,
                            2,
                        ), (string) $record->frozen_change, 2), (string) $record->pending_withdrawal_change, 2);
                    }),
                TextColumn::make('pending_withdrawal_change')->label(__('settlement.txn.pending_withdrawal_change'))->money('usd')->toggleable(),
                TextColumn::make('balance_after')->label(__('admin.finance.txn.balance_after'))->money('usd'),
                TextColumn::make('order.order_no')->label(__('admin.finance.txn.order_no'))->placeholder('—'),
                TextColumn::make('operator.name')->label(__('admin.finance.txn.operator'))->placeholder(__('admin.finance.txn.system')),
                TextColumn::make('reason')->label(__('admin.finance.txn.reason'))->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('admin.finance.txn.type'))->options(fn (): array => __('settlement.txn.types')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMerchantBalanceTransactions::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::FINANCE_VIEW);
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

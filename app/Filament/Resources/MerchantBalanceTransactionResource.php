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
 * 余额流水台账（资金管理），只读。展示每一次总余额的增减来源、金额、
 * 变动后余额、关联单据与操作人。数据由 BalanceService 写入，后台不提供增删改。
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
                    ->formatStateUsing(fn (string $state) => __('admin.finance.txn.types.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'order_paid' => 'success',
                        'refund', 'refund_fee', 'chargeback', 'chargeback_fee', 'withdrawal' => 'danger',
                        'manual_adjust' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('amount')->label(__('admin.finance.txn.amount'))->money('usd')
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('balance_after')->label(__('admin.finance.txn.balance_after'))->money('usd'),
                TextColumn::make('order.order_no')->label(__('admin.finance.txn.order_no'))->placeholder('—'),
                TextColumn::make('operator.name')->label(__('admin.finance.txn.operator'))->placeholder(__('admin.finance.txn.system')),
                TextColumn::make('reason')->label(__('admin.finance.txn.reason'))->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('admin.finance.txn.type'))->options([
                    'order_paid' => __('admin.finance.txn.types.order_paid'),
                    'refund' => __('admin.finance.txn.types.refund'),
                    'refund_fee' => __('admin.finance.txn.types.refund_fee'),
                    'chargeback' => __('admin.finance.txn.types.chargeback'),
                    'chargeback_fee' => __('admin.finance.txn.types.chargeback_fee'),
                    'withdrawal' => __('admin.finance.txn.types.withdrawal'),
                    'manual_adjust' => __('admin.finance.txn.types.manual_adjust'),
                ]),
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

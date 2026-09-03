<?php

namespace App\Filament\Resources;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\MerchantWithdrawalResource\Pages;
use App\Filament\Support\FinanceSecurity;
use App\Models\MerchantWithdrawal;
use App\Services\BalanceService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * 提现管理（资金管理）。商户侧发起提现申请（申请即冻结余额），
 * 拥有 withdrawals.review 权限者审核放款或驳回。审核放款/驳回都需要
 * 资金操作强制 2FA（见 FinanceSecurity）。金额记账口径统一 USD。
 *
 * 权限：finance.view 可见列表；withdrawals.request 可发起申请；
 * withdrawals.review 可审核放款/驳回；超级管理员通过 Gate::before 全通。
 */
class MerchantWithdrawalResource extends Resource
{
    protected static ?string $model = MerchantWithdrawal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.finance');
    }

    public static function getModelLabel(): string
    {
        return __('admin.finance.withdrawal.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.finance.withdrawal.model_label_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable()
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin),
                TextColumn::make('amount')->label(__('admin.finance.withdrawal.amount'))->money('usd')->sortable(),
                TextColumn::make('status')->label(__('admin.finance.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.finance.withdrawal.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('payout_account')->label(__('admin.finance.withdrawal.payout_account'))->toggleable(),
                TextColumn::make('requestedBy.name')->label(__('admin.finance.withdrawal.requested_by'))->placeholder('—'),
                TextColumn::make('reviewedBy.name')->label(__('admin.finance.withdrawal.reviewed_by'))->placeholder('—'),
                TextColumn::make('reviewed_at')->label(__('admin.finance.withdrawal.reviewed_at'))->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->label(__('admin.finance.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.finance.fields.status'))->options([
                    'pending' => __('admin.finance.withdrawal.statuses.pending'),
                    'approved' => __('admin.finance.withdrawal.statuses.approved'),
                    'rejected' => __('admin.finance.withdrawal.statuses.rejected'),
                ]),
            ])
            ->recordActions([
                static::approveAction(),
                static::rejectAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('admin.finance.withdrawal.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (MerchantWithdrawal $record) => $record->isPending() && auth()->user()->can(Permissions::WITHDRAWALS_REVIEW))
            ->modalHeading(__('admin.finance.withdrawal.approve_heading'))
            ->modalDescription(fn (MerchantWithdrawal $record) => __('admin.finance.withdrawal.approve_desc', ['amount' => number_format((float) $record->amount, 2)]))
            ->schema([
                Textarea::make('review_remark')->label(__('admin.finance.withdrawal.review_remark'))->rows(2)->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (MerchantWithdrawal $record, array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    app(BalanceService::class)->approveWithdrawal($record, $user, $data['review_remark'] ?? null);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.finance.withdrawal.approved'))->success()->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('admin.finance.withdrawal.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (MerchantWithdrawal $record) => $record->isPending() && auth()->user()->can(Permissions::WITHDRAWALS_REVIEW))
            ->modalHeading(__('admin.finance.withdrawal.reject_heading'))
            ->schema([
                Textarea::make('review_remark')->label(__('admin.finance.withdrawal.reject_reason'))->required()->rows(2)->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (MerchantWithdrawal $record, array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    app(BalanceService::class)->rejectWithdrawal($record, $user, $data['review_remark'] ?? null);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.finance.withdrawal.rejected'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMerchantWithdrawals::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::FINANCE_VIEW);
    }

    public static function canCreate(): bool
    {
        // 申请提现走列表页的自定义头部动作（需要冻结余额 + 2FA），不用标准新建页。
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

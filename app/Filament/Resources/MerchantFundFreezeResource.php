<?php

namespace App\Filament\Resources;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\MerchantFundFreezeResource\Pages;
use App\Filament\Support\FinanceSecurity;
use App\Models\Merchant;
use App\Models\MerchantFundFreeze;
use App\Services\BalanceService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 保证金流水：展示订单结算划入的循环保证金，以及人工冻结可提现余额形成的固定保证金。
 * 固定保证金可人工解冻或到期自动解冻，循环保证金仅到期自动释放
 * （见 BalanceService::freezeFunds()/releaseFundFreeze()，定时命令
 * fund-freezes:release-due）。人工解冻需要资金操作强制 2FA（见 FinanceSecurity）。
 *
 * 权限：fund_freezes.view 可见列表；fund_freezes.create 可发起冻结（走列表页
 * 自定义头部动作，同 MerchantWithdrawalResource 的"申请提现"）；
 * fund_freezes.release 可手动解冻。这三个权限只下发给"商户管理员"角色
 * （见 Permissions::merchantScoped()）和超级管理员（Gate::before 全通），
 * 不下发给财务管理员等其它默认子角色。
 */
class MerchantFundFreezeResource extends Resource
{
    protected static ?string $model = MerchantFundFreeze::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.finance');
    }

    public static function getModelLabel(): string
    {
        return __('settlement.reserve.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('settlement.reserve.model_label_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable()
                    ->visible(fn () => (bool) auth()->user()?->isPlatformStaff()),
                TextColumn::make('source')->label(__('settlement.reserve.source'))
                    ->state(fn (MerchantFundFreeze $record): string => $record->order_id ? 'rolling' : 'fixed')
                    ->formatStateUsing(fn (string $state): string => __('settlement.reserve.sources.'.$state))
                    ->badge(),
                TextColumn::make('order.order_no')->label(__('settlement.fields.order'))->searchable()->placeholder('—'),
                TextColumn::make('order.settlement.settled_at')->label(__('settlement.fields.settled_at'))->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('amount')->label(__('admin.finance.fund_freeze.amount'))->money('usd')->sortable(),
                TextColumn::make('remaining_amount')->label(__('settlement.reserve.remaining_amount'))
                    ->state(fn (MerchantFundFreeze $record): string => $record->remainingAmount())->money('usd'),
                TextColumn::make('status')->label(__('admin.finance.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('settlement.reserve.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        MerchantFundFreeze::STATUS_FROZEN => 'warning',
                        MerchantFundFreeze::STATUS_RELEASED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('reason')->label(__('admin.finance.fund_freeze.reason'))->limit(50),
                TextColumn::make('release_at')->label(__('admin.finance.fund_freeze.release_at'))->dateTime()
                    ->placeholder(__('admin.finance.fund_freeze.manual_only')),
                TextColumn::make('frozenBy.name')->label(__('admin.finance.fund_freeze.frozen_by'))->placeholder('—'),
                TextColumn::make('frozen_at')->label(__('admin.finance.fund_freeze.frozen_at'))->dateTime()->sortable(),
                TextColumn::make('releasedBy.name')->label(__('admin.finance.fund_freeze.released_by'))->placeholder('—'),
                TextColumn::make('released_at')->label(__('admin.finance.fund_freeze.released_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('merchant_id')
                    ->label(__('admin.finance.fields.merchant'))
                    ->visible(fn () => (bool) auth()->user()?->isPlatformStaff())
                    ->options(function () {
                        $user = auth()->user();

                        if ($user->isMerchantManager()) {
                            return $user->ownedMerchants()->orderBy('name')->pluck('name', 'id');
                        }

                        return Merchant::query()->orderBy('name')->pluck('name', 'id');
                    })
                    ->searchable(),

                SelectFilter::make('status')->label(__('admin.finance.fields.status'))->options([
                    MerchantFundFreeze::STATUS_FROZEN => __('settlement.reserve.statuses.frozen'),
                    MerchantFundFreeze::STATUS_RELEASED => __('settlement.reserve.statuses.released'),
                    MerchantFundFreeze::STATUS_CONSUMED => __('settlement.reserve.statuses.consumed'),
                ]),
                SelectFilter::make('source')->label(__('settlement.reserve.source'))
                    ->options([
                        'rolling' => __('settlement.reserve.sources.rolling'),
                        'fixed' => __('settlement.reserve.sources.fixed'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'rolling' => $query->whereNotNull('order_id'),
                            'fixed' => $query->whereNull('order_id'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                static::releaseAction(),
            ])
            ->defaultSort('frozen_at', 'desc');
    }

    public static function releaseAction(): Action
    {
        return Action::make('releaseFundFreeze')
            ->label(__('admin.finance.fund_freeze.release'))
            ->icon('heroicon-o-lock-open')
            ->color('success')
            ->visible(fn (MerchantFundFreeze $record) => $record->isFrozen() && ! $record->order_id && auth()->user()->can(Permissions::FUND_FREEZES_RELEASE))
            ->modalHeading(__('admin.finance.fund_freeze.release_heading'))
            ->modalDescription(fn (MerchantFundFreeze $record) => __('admin.finance.fund_freeze.release_desc', ['amount' => number_format((float) $record->remainingAmount(), 2)]))
            ->schema([
                Textarea::make('release_remark')->label(__('admin.finance.fund_freeze.release_remark'))->rows(2)->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (MerchantFundFreeze $record, array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    $released = app(BalanceService::class)->releaseFundFreeze($record, $user, MerchantFundFreeze::RELEASE_TYPE_MANUAL, $data['release_remark'] ?? null);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($released
                        ? __('admin.finance.fund_freeze.released')
                        : __('admin.finance.fund_freeze.already_released'))
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMerchantFundFreezes::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::FUND_FREEZES_VIEW);
    }

    public static function canCreate(): bool
    {
        // 发起冻结走列表页的自定义头部动作（需要选商户 + 2FA），不用标准新建页。
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

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

/**
 * 通用人工资金冻结（资金管理），与订单/提现无关：商户管理员或超级管理员
 * 直接对商户冻结一笔资金，冻结后走人工解冻或到期自动解冻
 * （见 BalanceService::freezeFunds()/releaseFundFreeze()，定时命令
 * fund-freezes:release-due）。解冻都需要资金操作强制 2FA（见 FinanceSecurity）。
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
        return __('admin.finance.fund_freeze.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.finance.fund_freeze.model_label_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable()
                    ->visible(fn () => (bool) auth()->user()?->isPlatformStaff()),
                TextColumn::make('amount')->label(__('admin.finance.fund_freeze.amount'))->money('usd')->sortable(),
                TextColumn::make('status')->label(__('admin.finance.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.finance.fund_freeze.statuses.'.$state))
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
                    MerchantFundFreeze::STATUS_FROZEN => __('admin.finance.fund_freeze.statuses.frozen'),
                    MerchantFundFreeze::STATUS_RELEASED => __('admin.finance.fund_freeze.statuses.released'),
                ]),
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
            ->visible(fn (MerchantFundFreeze $record) => $record->isFrozen() && auth()->user()->can(Permissions::FUND_FREEZES_RELEASE))
            ->modalHeading(__('admin.finance.fund_freeze.release_heading'))
            ->modalDescription(fn (MerchantFundFreeze $record) => __('admin.finance.fund_freeze.release_desc', ['amount' => number_format((float) $record->amount, 2)]))
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

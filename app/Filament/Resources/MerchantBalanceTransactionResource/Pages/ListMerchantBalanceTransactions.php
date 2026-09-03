<?php

namespace App\Filament\Resources\MerchantBalanceTransactionResource\Pages;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\MerchantBalanceTransactionResource;
use App\Filament\Support\FinanceSecurity;
use App\Services\BalanceService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMerchantBalanceTransactions extends ListRecords
{
    protected static string $resource = MerchantBalanceTransactionResource::class;

    /**
     * 商户用户在此页顶部看到自己当前余额；超级管理员无单一商户上下文，不显示。
     */
    public function getSubheading(): ?string
    {
        $merchant = auth()->user()->merchant;

        if (! $merchant) {
            return null;
        }

        return __('admin.finance.balance_summary', [
            'balance' => number_format((float) $merchant->balance, 2),
            'frozen' => number_format((float) $merchant->frozen_balance, 2),
            'available' => number_format((float) $merchant->availableBalance(), 2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->adjustBalanceAction(),
        ];
    }

    /**
     * 商户侧自助人工调整本商户余额（增/减）。需 balance.adjust 权限 + 2FA。
     * 平台超级管理员改用"商户管理"里对应商户的调整动作（可指定任意商户）。
     */
    private function adjustBalanceAction(): Action
    {
        return Action::make('adjustBalance')
            ->label(__('admin.finance.adjust.action'))
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->visible(fn () => auth()->user()->merchant_id
                && auth()->user()->can(Permissions::BALANCE_ADJUST))
            ->modalHeading(__('admin.finance.adjust.action'))
            ->modalDescription(fn () => __('admin.finance.adjust.current_balance', [
                'amount' => number_format((float) (auth()->user()->merchant?->balance ?? 0), 2),
            ]))
            ->schema([
                TextInput::make('amount')
                    ->label(__('admin.finance.adjust.amount'))
                    ->helperText(__('admin.finance.adjust.amount_help'))
                    ->numeric()
                    ->required()
                    ->prefix('$'),
                Textarea::make('reason')
                    ->label(__('admin.finance.adjust.reason'))
                    ->required()
                    ->rows(2)
                    ->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    app(BalanceService::class)->manualAdjust($user->merchant, $data['amount'], $user, $data['reason']);
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.finance.adjust.success'))->success()->send();
            });
    }
}

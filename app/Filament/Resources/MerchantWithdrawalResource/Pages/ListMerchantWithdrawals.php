<?php

namespace App\Filament\Resources\MerchantWithdrawalResource\Pages;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\MerchantWithdrawalResource;
use App\Filament\Support\FinanceSecurity;
use App\Services\BalanceService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMerchantWithdrawals extends ListRecords
{
    protected static string $resource = MerchantWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->requestWithdrawalAction(),
        ];
    }

    /**
     * 商户发起提现申请（申请即冻结余额）。仅对"归属某商户且有 withdrawals.request
     * 权限"的用户可见——超级管理员没有单一商户上下文，走审核而非申请。
     */
    private function requestWithdrawalAction(): Action
    {
        return Action::make('requestWithdrawal')
            ->label(__('admin.finance.withdrawal.request'))
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn () => auth()->user()->merchant_id
                && auth()->user()->can(Permissions::WITHDRAWALS_REQUEST))
            ->modalHeading(__('admin.finance.withdrawal.request'))
            ->modalDescription(fn () => __('admin.finance.withdrawal.available', [
                'amount' => number_format((float) (auth()->user()->merchant?->availableBalance() ?? 0), 2),
            ]))
            ->schema([
                TextInput::make('amount')
                    ->label(__('admin.finance.withdrawal.amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->prefix('$'),
                TextInput::make('payout_account')
                    ->label(__('admin.finance.withdrawal.payout_account'))
                    ->maxLength(255),
                Textarea::make('remark')
                    ->label(__('admin.finance.withdrawal.remark'))
                    ->rows(2)
                    ->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    app(BalanceService::class)->requestWithdrawal(
                        $user->merchant,
                        $data['amount'],
                        $user,
                        $data['payout_account'] ?? null,
                        $data['remark'] ?? null,
                    );
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.finance.withdrawal.requested'))->success()->send();
            });
    }
}

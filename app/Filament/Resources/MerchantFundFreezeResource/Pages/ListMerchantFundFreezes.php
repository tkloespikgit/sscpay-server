<?php

namespace App\Filament\Resources\MerchantFundFreezeResource\Pages;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\MerchantFundFreezeResource;
use App\Filament\Support\FinanceSecurity;
use App\Models\Merchant;
use App\Services\BalanceService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMerchantFundFreezes extends ListRecords
{
    protected static string $resource = MerchantFundFreezeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->openFreezeAction(),
        ];
    }

    /**
     * 发起资金冻结。超级管理员/商户级管理员没有单一商户上下文，需要选商户；
     * 普通商户管理员锁定成自己所在商户——同 UserResource/ApplicationResource
     * 里 $canPickMerchant 的既有写法。
     */
    private function openFreezeAction(): Action
    {
        $viewer = auth()->user();
        $canPickMerchant = (bool) $viewer?->isPlatformStaff();

        return Action::make('openFundFreeze')
            ->label(__('admin.finance.fund_freeze.create'))
            ->icon('heroicon-o-lock-closed')
            ->visible(fn () => auth()->user()->can(Permissions::FUND_FREEZES_CREATE))
            ->modalHeading(__('admin.finance.fund_freeze.create'))
            ->schema([
                Select::make('merchant_id')
                    ->label(__('admin.finance.fields.merchant'))
                    ->options(function () use ($viewer) {
                        if ($viewer->isMerchantManager()) {
                            return $viewer->ownedMerchants()->where('status', true)->pluck('name', 'id');
                        }

                        return Merchant::query()->where('status', true)->pluck('name', 'id');
                    })
                    ->required()
                    ->searchable()
                    ->disabled(! $canPickMerchant)
                    ->default(fn () => $canPickMerchant ? null : $viewer->merchant_id)
                    ->dehydrated(),

                TextInput::make('amount')
                    ->label(__('admin.finance.fund_freeze.amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->prefix('$'),

                DateTimePicker::make('release_at')
                    ->label(__('admin.finance.fund_freeze.release_at'))
                    ->helperText(__('admin.finance.fund_freeze.release_at_help'))
                    ->native(false)
                    ->minDate(now()),

                Textarea::make('reason')
                    ->label(__('admin.finance.fund_freeze.reason'))
                    ->required()
                    ->rows(2)
                    ->maxLength(500),

                FinanceSecurity::codeField(),
            ])
            ->action(function (array $data) {
                try {
                    $user = auth()->user();
                    FinanceSecurity::assertVerified($user, $data['mfa_code'] ?? null);
                    $merchant = Merchant::query()->findOrFail($data['merchant_id']);
                    app(BalanceService::class)->freezeFunds(
                        $merchant,
                        $data['amount'],
                        $user,
                        $data['reason'],
                        $data['release_at'] ?? null,
                    );
                } catch (BalanceOperationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('admin.finance.fund_freeze.created'))->success()->send();
            });
    }
}

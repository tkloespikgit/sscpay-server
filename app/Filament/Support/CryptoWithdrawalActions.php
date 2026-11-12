<?php

namespace App\Filament\Support;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\CryptoAssetBalanceResource;
use App\Filament\Resources\CryptoWithdrawalResource;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoWithdrawal;
use App\Models\Merchant;
use App\Models\User;
use App\Services\CryptoBalanceService;
use App\Support\CryptoFinanceAccess;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

final class CryptoWithdrawalActions
{
    public static function request(): Action
    {
        return Action::make('requestWithdrawal')->label(__('crypto.finance.actions.request'))
            ->visible(fn (CryptoAssetBalance $record): bool => self::allowed($record->merchant, Permissions::CRYPTO_WITHDRAWALS_REQUEST))
            ->modalDescription(fn (CryptoAssetBalance $record): string => __('crypto.finance.request_help', [
                'network' => __('crypto.finance.networks.'.$record->network),
                'available' => CryptoFinanceDisplay::amount($record, $record->availableUnits()),
            ]))
            ->schema([
                Hidden::make('request_key')->default(fn (): string => (string) Str::uuid())->required()->rules(['uuid']),
                TextInput::make('amount')->label(__('crypto.finance.fields.amount_units'))->inputMode('decimal')->required()->maxLength(115),
                TextInput::make('fee_limit')->label(__('crypto.finance.fields.fee_limit_units'))->inputMode('decimal')->required()->default('0')
                    ->maxLength(115)->helperText(__('crypto.finance.fee_help')),
                TextInput::make('destination_address')->label(__('crypto.finance.fields.destination_address'))->required()->maxLength(128)
                    ->helperText(__('crypto.finance.address_help')),
                Textarea::make('remark')->label(__('crypto.finance.fields.remark'))->maxLength(500),
                FinanceSecurity::codeField(),
            ])
            ->action(function (CryptoAssetBalance $record, array $data): void {
                abort_unless(CryptoAssetBalanceResource::canView($record), 403);
                try {
                    app(CryptoBalanceService::class)->requestWithdrawal(
                        $record->merchant_id, $record->id, auth()->user(), $data['request_key'], $data['amount'],
                        $data['fee_limit'], $data['destination_address'], $data['remark'] ?? null, $data['mfa_code'] ?? null,
                    );
                    Notification::make()->success()->title(__('crypto.finance.saved'))->send();
                } catch (BalanceOperationException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    /** @return list<Action> */
    public static function review(): array
    {
        return array_map(fn (string $action): Action => self::decision($action), ['approve', 'reject', 'cancel', 'complete']);
    }

    private static function decision(string $name): Action
    {
        $permission = match ($name) {
            'cancel' => Permissions::CRYPTO_WITHDRAWALS_REQUEST,
            'complete' => Permissions::CRYPTO_WITHDRAWALS_CONFIRM,
            default => Permissions::CRYPTO_WITHDRAWALS_REVIEW,
        };

        return Action::make($name)->label(__('crypto.finance.actions.'.$name))
            ->color(match ($name) {
                'reject', 'cancel' => 'danger', 'complete' => 'warning', default => 'success'
            })
            ->visible(function (CryptoWithdrawal $record) use ($name, $permission): bool {
                $statuses = match ($name) {
                    'complete' => ['approved'], 'reject' => ['pending', 'approved'], default => ['pending']
                };

                return in_array($record->status, $statuses, true)
                    && ($name !== 'cancel' || (int) $record->requested_by === (int) auth()->id())
                    && self::allowed($record->merchant, $permission, platformOnly: $name !== 'cancel');
            })
            ->modalDescription(__('crypto.finance.action_help.'.$name))
            ->schema([
                TextInput::make('actual_fee')->label(__('crypto.finance.fields.actual_fee_units'))->inputMode('decimal')->maxLength(115)
                    ->default('0')->required($name === 'complete')->visible($name === 'complete')->helperText(__('crypto.finance.fee_help')),
                TextInput::make('manual_reference')->label(__('crypto.finance.fields.manual_reference'))->maxLength(128)
                    ->required($name === 'complete')->visible($name === 'complete'),
                Textarea::make('reason')->label(__('crypto.finance.fields.reason'))->required()->maxLength(500),
                Checkbox::make('no_external_payment')->label(__('crypto.finance.no_external_payment'))->accepted()
                    ->required()->visible($name === 'reject'),
                Checkbox::make('manual_confirmation')->label(__('crypto.finance.manual_confirmation'))->accepted()
                    ->required()->visible($name === 'complete'),
                FinanceSecurity::codeField(),
            ])
            ->action(function (CryptoWithdrawal $record, array $data) use ($name): void {
                abort_unless(CryptoWithdrawalResource::canView($record), 403);
                try {
                    app(CryptoBalanceService::class)->processWithdrawal(
                        $record->merchant_id, $record->id, auth()->user(), $name, $data['reason'], $data['mfa_code'] ?? null,
                        $data['actual_fee'] ?? null, $data['manual_reference'] ?? null, (bool) ($data['no_external_payment'] ?? false),
                    );
                    Notification::make()->success()->title(__('crypto.finance.saved'))->send();
                } catch (BalanceOperationException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    private static function allowed(?Merchant $merchant, string $permission, bool $platformOnly = false): bool
    {
        $user = auth()->user();

        return $merchant !== null && $user instanceof User && CryptoFinanceAccess::allowed($user, $permission, $merchant, $platformOnly);
    }
}

<?php

namespace App\Filament\Resources;

use App\Exceptions\BalanceOperationException;
use App\Filament\Resources\CryptoPaymentReviewResource\Pages\ManageCryptoPaymentReviews;
use App\Filament\Support\CryptoFinanceDisplay;
use App\Filament\Support\CryptoFinanceResource;
use App\Filament\Support\CryptoOrderDisplay;
use App\Filament\Support\FinanceSecurity;
use App\Models\CryptoPaymentResolution;
use App\Models\Order;
use App\Services\CryptoBalanceService;
use App\Services\CryptoPaymentEvidenceService;
use App\Services\CryptoQuoteService;
use App\Support\CryptoAmount;
use App\Support\CryptoFinanceAccess;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CryptoPaymentReviewResource extends CryptoFinanceResource
{
    protected static ?string $model = Order::class;

    protected static string $cryptoLabel = 'reviews';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('payment_type', 'crypto')->whereHas('cryptoRequests')
            ->with([...CryptoOrderDisplay::RELATIONS, 'cryptoRequests.cryptoAddress', 'cryptoResolutions.operator:id,name']);
    }

    public static function table(Table $table): Table
    {
        return $table->description(__('crypto.review.help'))->columns([
            TextColumn::make('order_no')->label(__('admin.finance.txn.order_no'))->searchable(),
            TextColumn::make('merchant.name')->label(__('admin.finance.fields.merchant'))->searchable(),
            TextColumn::make('denomination')->label(__('crypto.order.denomination'))->state(fn (Order $record): string => CryptoOrderDisplay::denomination($record)),
            TextColumn::make('accounting_state')->label(__('crypto.review.accounting_state'))->state(fn (Order $record): string => CryptoOrderDisplay::state($record))->badge(),
            TextColumn::make('net')->label(__('crypto.order.net'))->state(fn (Order $record): string => CryptoOrderDisplay::amount($record)),
            TextColumn::make('fee')->label(__('crypto.order.fee'))->state(fn (Order $record): string => CryptoOrderDisplay::amount($record, 'fee')),
            TextColumn::make('created_at')->label(__('admin.finance.fields.created_at'))->dateTime()->sortable(),
        ])->filters([
            Filter::make('review')->label(__('crypto.order.review'))->query(fn (Builder $query): Builder => $query->whereHas('cryptoRequests',
                fn (Builder $q): Builder => $q->where(fn ($state) => $state->where('status', 'review')->orWhereNotNull('review_reason')->orWhere('status', 'partial')))),
        ])->recordActions([ViewAction::make(), self::reconcileAction(), self::recheckAction()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('order_no')->label(__('admin.finance.txn.order_no')),
            TextEntry::make('quote')->label(__('crypto.order.rate'))->state(fn (Order $record): string => CryptoOrderDisplay::rate($record))->columnSpanFull(),
            RepeatableEntry::make('cryptoRequests')->label(__('crypto.review.requests'))->columnSpanFull()->schema([
                TextEntry::make('id')->label(__('crypto.finance.fields.request_id')),
                TextEntry::make('cryptoAddress.address')->label(__('crypto.finance.fields.receive_address'))->copyable(),
                TextEntry::make('due_units')->label(__('crypto.review.expected'))->formatStateUsing(fn ($record, string $state): string => CryptoFinanceDisplay::amount($record, $state)),
                TextEntry::make('expires_at')->label(__('crypto.review.expires_at'))->dateTime(),
                TextEntry::make('status')->label(__('crypto.finance.fields.request_status'))->formatStateUsing(fn (string $state): string => __('crypto.finance.request_states.'.$state)),
                TextEntry::make('review_reason')->label(__('crypto.finance.fields.review_reason'))->placeholder('—'),
                TextEntry::make('history_checked_at')->label(__('crypto.review.history_checked_at'))->dateTime()->placeholder('—'),
            ])->columns(3),
            RepeatableEntry::make('cryptoResolutions')->label(__('crypto.review.audit'))->columnSpanFull()->schema([
                TextEntry::make('action')->label(__('crypto.finance.fields.action'))->formatStateUsing(fn (string $state): string => __('crypto.review.actions.'.$state)),
                TextEntry::make('operator.name')->label(__('admin.finance.txn.operator')),
                TextEntry::make('created_at')->label(__('admin.finance.fields.created_at'))->dateTime(),
                ...array_map(fn (string $field): TextEntry => TextEntry::make($field)->label(__('crypto.review.fields.'.$field))
                    ->formatStateUsing(fn (CryptoPaymentResolution $record, string $state): string => CryptoFinanceDisplay::amount($record, $state)),
                    ['gross_before_units', 'gross_after_units', 'fee_before_units', 'fee_after_units', 'net_before_units', 'net_after_units', 'balance_before_units', 'balance_after_units', 'debt_before_units', 'debt_after_units']),
                TextEntry::make('reason')->label(__('crypto.finance.fields.reason'))->columnSpanFull(),
                TextEntry::make('evidence_hash')->label(__('crypto.review.evidence_hash'))->copyable()->columnSpanFull(),
            ])->columns(3),
        ]);
    }

    private static function allowed(Order $order): bool
    {
        return CryptoFinanceAccess::allowed(CryptoFinanceAccess::user(), Permissions::CRYPTO_PAYMENTS_RECONCILE, $order->merchant, platformOnly: true);
    }

    private static function reconcileAction(): Action
    {
        return Action::make('reconcile')->label(__('crypto.review.reconcile'))->color('warning')
            ->visible(fn (Order $record): bool => self::allowed($record))->modalDescription(__('crypto.review.confirm_help'))
            ->fillForm(function (Order $record): array {
                $evidence = app(CryptoPaymentEvidenceService::class)->capture($record);
                $fee = app(CryptoQuoteService::class)->feeForGross($evidence['gross'], $record->cryptoIncome?->fee_snapshot ?? $evidence['request']->quote_snapshot);
                $decimals = $evidence['request']->decimals;
                $asset = $evidence['request']->asset;

                return ['proposed_fee' => CryptoAmount::format($fee, $decimals).' '.$asset,
                    'proposed_net' => CryptoAmount::format(bcsub($evidence['gross'], $fee, 0), $decimals).' '.$asset,
                    'current_net' => CryptoOrderDisplay::amount($record), 'request_key' => (string) Str::uuid(), 'evidence_hash' => $evidence['hash'],
                    'actual_received' => CryptoAmount::format($evidence['gross'], $evidence['request']->decimals).' '.$evidence['request']->asset];
            })
            ->schema([
                Hidden::make('request_key')->required()->rules(['uuid']),
                Hidden::make('evidence_hash')->required()->rules(['size:64']),
                TextInput::make('actual_received')->label(__('crypto.review.observed'))->disabled()->dehydrated(false),
                TextInput::make('current_net')->label(__('crypto.review.fields.net_before_units'))->disabled()->dehydrated(false),
                TextInput::make('proposed_fee')->label(__('crypto.review.fields.fee_after_units'))->disabled()->dehydrated(false),
                TextInput::make('proposed_net')->label(__('crypto.review.fields.net_after_units'))->disabled()->dehydrated(false),
                Textarea::make('reason')->label(__('crypto.finance.fields.reason'))->required()->maxLength(500),
                Checkbox::make('acknowledge')->label(__('crypto.review.acknowledge'))->required()->accepted(),
                FinanceSecurity::codeField(),
            ])->action(function (Order $record, array $data): void {
                abort_unless(self::canView($record), 403);
                try {
                    app(CryptoBalanceService::class)->reconcileOrder($record->merchant_id, $record->id, auth()->user(),
                        $data['request_key'], $data['evidence_hash'], $data['reason'], $data['mfa_code'] ?? null);
                    Notification::make()->success()->title(__('crypto.finance.saved'))->send();
                } catch (BalanceOperationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    private static function recheckAction(): Action
    {
        return Action::make('recheck')->label(__('crypto.review.actions.recheck'))->visible(fn (Order $record): bool => self::allowed($record))
            ->modalDescription(__('crypto.review.recheck_help'))->schema([
                Textarea::make('reason')->label(__('crypto.finance.fields.reason'))->required()->maxLength(500), FinanceSecurity::codeField(),
            ])->action(function (Order $record, array $data): void {
                abort_unless(self::canView($record), 403);
                try {
                    app(CryptoBalanceService::class)->recheckOrder($record->merchant_id, $record->id, auth()->user(), $data['reason'], $data['mfa_code'] ?? null);
                    Notification::make()->success()->title(__('crypto.finance.saved'))->send();
                } catch (BalanceOperationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageCryptoPaymentReviews::route('/')];
    }
}

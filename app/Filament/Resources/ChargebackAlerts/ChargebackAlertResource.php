<?php

namespace App\Filament\Resources\ChargebackAlerts;

use App\Filament\Resources\ChargebackAlerts\Pages\ManageChargebackAlerts;
use App\Filament\Resources\OrderResource;
use App\Models\ChargebackAlert;
use App\Models\Order;
use App\Models\User;
use App\Services\ChargebackAlerts\ChargebackAlertDrivers;
use App\Services\ChargebackAlerts\ChargebackAlertMatcher;
use App\Services\ChargebackAlerts\ChargebackAlertPolicy;
use App\Services\ChargebackAlerts\ChargebackAlertProcessing;
use App\Services\ChargebackAlerts\ChargebackFeedback;
use App\Services\ChargebackAlerts\SendsChargebackFeedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChargebackAlertResource extends Resource
{
    protected static ?string $model = ChargebackAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $recordTitleAttribute = 'external_id';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.order_management');
    }

    public static function getModelLabel(): string
    {
        return __('chargeback_alerts.alert');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chargeback_alerts.alerts');
    }

    public static function canViewAny(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(ChargebackAlertPolicy::class)->viewAny($actor);
    }

    public static function canView($record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(ChargebackAlertPolicy::class)->view($actor, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = auth()->user();
        $query = parent::getEloquentQuery()->with('account:id,provider,enabled,services')
            ->withExists(['feedback as has_open_or_submitted_feedback' => fn (Builder $feedback) => $feedback
                ->whereIn('status', ['pending', 'sending', 'unknown', 'submitted'])]);
        if (! $actor instanceof User || ! static::canViewAny()) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->is_super_admin ? $query : $query->whereIn('chargeback_alerts.merchant_id', $actor->manageableMerchantIds() ?? [])->whereNotNull('order_id');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('external_id')->label(__('chargeback_alerts.external_id'))->searchable(),
            TextColumn::make('service')->label(__('chargeback_alerts.service'))->formatStateUsing(fn (string $state): string => strtoupper($state))->badge(),
            TextColumn::make('order.order_no')->label(__('chargeback_alerts.order_no'))->searchable()->placeholder('—'),
            TextColumn::make('merchant.name')->label(__('chargeback_alerts.merchant'))->searchable()->placeholder('—'),
            TextColumn::make('case_amount')->label(__('chargeback_alerts.case_amount'))->formatStateUsing(fn ($state, ChargebackAlert $record): string => trim($state.' '.$record->case_currency))->placeholder('—'),
            TextColumn::make('match_status')->label(__('chargeback_alerts.match_status'))->badge()
                ->formatStateUsing(fn (string $state): string => __("chargeback_alerts.match_states.{$state}")),
            TextColumn::make('feedback_due_at')->label(__('chargeback_alerts.deadline'))->dateTime()->sortable()->placeholder('—'),
            TextColumn::make('occurred_at')->label(__('chargeback_alerts.occurred_at'))->dateTime()->sortable()->placeholder('—'),
        ])->filters([
            SelectFilter::make('match_status')->label(__('chargeback_alerts.match_status'))->options(__('chargeback_alerts.match_states')),
        ])->recordActions([
            ViewAction::make()->authorize(fn (ChargebackAlert $record): bool => self::canView($record))->modalWidth(Width::SixExtraLarge),
            self::attachAction(),
            self::reviewAction(),
            self::feedbackAction(),
        ])->recordAction('view')->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('external_id')->label(__('chargeback_alerts.external_id'))->copyable(),
            TextEntry::make('service')->label(__('chargeback_alerts.service'))->formatStateUsing(fn (string $state): string => strtoupper($state)),
            TextEntry::make('scope_key')->label(__('chargeback_alerts.scope'))->placeholder('—'),
            TextEntry::make('descriptor')->label(__('chargeback_alerts.descriptor'))->placeholder('—'),
            TextEntry::make('arn')->label('ARN')->copyable()->placeholder('—'),
            TextEntry::make('provider_transaction_id')->label(__('chargeback_alerts.references.psp_transaction'))->placeholder('—'),
            TextEntry::make('provider_order_reference')->label(__('chargeback_alerts.provider_order_reference'))->placeholder('—'),
            TextEntry::make('alert_type')->label(__('chargeback_alerts.alert_type'))->placeholder('—'),
            TextEntry::make('reason_code')->label(__('chargeback_alerts.reason_code'))->placeholder('—'),
            TextEntry::make('remote_status')->label(__('chargeback_alerts.remote_status'))->placeholder('—'),
            TextEntry::make('charge_level')->label(__('chargeback_alerts.charge_level'))->placeholder('—'),
            TextEntry::make('reviewed_at')->label(__('chargeback_alerts.reviewed_at'))->dateTime()->placeholder('—'),
            TextEntry::make('trade_amount')->label(__('chargeback_alerts.trade_amount'))->formatStateUsing(fn ($state, ChargebackAlert $record): string => trim($state.' '.$record->trade_currency))->placeholder('—'),
            TextEntry::make('case_amount')->label(__('chargeback_alerts.case_amount'))->formatStateUsing(fn ($state, ChargebackAlert $record): string => trim($state.' '.$record->case_currency))->placeholder('—'),
            TextEntry::make('trade_at')->label(__('chargeback_alerts.trade_at'))->dateTime()->placeholder('—'),
            TextEntry::make('occurred_at')->label(__('chargeback_alerts.occurred_at'))->dateTime()->placeholder('—'),
            TextEntry::make('feedback_due_at')->label(__('chargeback_alerts.deadline'))->dateTime()->placeholder('—'),
            TextEntry::make('remote_feedback_at')->label(__('chargeback_alerts.remote_feedback_at'))->dateTime()->placeholder('—'),
            TextEntry::make('remote_feedback.outcome')->label(__('chargeback_alerts.outcome'))->placeholder('—'),
            TextEntry::make('remote_feedback.refund_status')->label(__('chargeback_alerts.refund_status'))->placeholder('—'),
            TextEntry::make('conflict')->hidden(fn (ChargebackAlert $record): bool => ! $record->evidence_conflict)
                ->hiddenLabel()->state(__('chargeback_alerts.evidence_conflict'))->color('warning')->columnSpanFull(),
            ViewEntry::make('candidates')->hiddenLabel()->columnSpanFull()->view('filament.resources.chargeback-alerts.candidates')
                ->viewData(function (ChargebackAlert $record, ChargebackAlertMatcher $matcher): array {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    return [
                        'matched' => $record->order_id !== null,
                        'orders' => $matcher->candidates($actor, $record)->map(fn (Order $order): array => [
                            'number' => $order->order_no, 'amount' => $order->amount.' '.$order->currency,
                            'time' => $order->paid_at?->toDateTimeString(),
                            'url' => ! $order->trashed() && OrderResource::canView($order)
                                ? OrderResource::getUrl('view', ['record' => $order]) : null,
                        ]),
                    ];
                }),
            RepeatableEntry::make('feedback_attempts')->label(__('chargeback_alerts.feedback_attempts'))->columnSpanFull()
                ->state(fn (ChargebackAlert $record): array => $record->feedback()->latest('id')->limit(20)->get(['id', 'status', 'result_code', 'attempts', 'created_at'])->toArray())
                ->schema([
                    TextEntry::make('status')->label(__('chargeback_alerts.feedback_status'))->formatStateUsing(fn (string $state): string => __("chargeback_alerts.feedback_states.{$state}"))->badge(),
                    TextEntry::make('attempts')->label(__('chargeback_alerts.attempts')),
                    TextEntry::make('result_code')->label(__('chargeback_alerts.result_code'))->placeholder('—'),
                    TextEntry::make('created_at')->label(__('chargeback_alerts.observed_at'))->dateTime(),
                ])->columns(4),
            RepeatableEntry::make('audit')->label(__('chargeback_alerts.audit'))->columnSpanFull()
                ->state(fn (ChargebackAlert $record): array => $record->events()->latest('id')->limit(20)->get(['id', 'kind', 'source', 'details', 'created_at'])->toArray())
                ->schema([
                    TextEntry::make('kind')->label(__('chargeback_alerts.action'))->formatStateUsing(fn (string $state): string => __("chargeback_alerts.event_types.{$state}")),
                    TextEntry::make('source')->label(__('chargeback_alerts.source'))->formatStateUsing(fn (string $state): string => __("chargeback_alerts.event_sources.{$state}")),
                    TextEntry::make('details.reason')->label(__('chargeback_alerts.note'))->placeholder('—'),
                    TextEntry::make('created_at')->label(__('chargeback_alerts.observed_at'))->dateTime(),
                ])->columns(4),
        ])->columns(3);
    }

    private static function attachAction(): Action
    {
        return Action::make('attachOrder')->label(__('chargeback_alerts.attach'))
            ->authorize(fn (ChargebackAlert $record): bool => self::authorized('matchOrder', $record))
            ->visible(fn (ChargebackAlert $record): bool => self::authorized('matchOrder', $record))
            ->schema([
                TextInput::make('order_no')->label(__('chargeback_alerts.order_no'))->required()->maxLength(32),
                Textarea::make('reason')->label(__('chargeback_alerts.note'))->required()->maxLength(250),
                Checkbox::make('confirm_arn')->label(__('chargeback_alerts.confirm_arn'))->visible(fn (ChargebackAlert $record): bool => $record->arn !== null),
            ])->action(function (ChargebackAlert $record, array $data, ChargebackAlertMatcher $matcher): void {
                $matcher->attach(self::actor(), $record->id, $data['order_no'], $data['reason'], (bool) ($data['confirm_arn'] ?? false));
                Notification::make()->title(__('chargeback_alerts.attached'))->success()->send();
            });
    }

    private static function reviewAction(): Action
    {
        $allowed = fn (ChargebackAlert $record): bool => $record->reviewed_at === null
            && (self::authorized('process', $record) || self::authorized('matchOrder', $record));

        return Action::make('confirmReview')->label(__('chargeback_alerts.review'))->authorize($allowed)->visible($allowed)
            ->schema([Textarea::make('note')->label(__('chargeback_alerts.note'))->required()->maxLength(250)])
            ->action(function (ChargebackAlert $record, array $data, ChargebackAlertProcessing $processing): void {
                $processing->confirmReview(self::actor(), $record->id, $data['note']);
                Notification::make()->title(__('chargeback_alerts.reviewed'))->success()->send();
            });
    }

    private static function feedbackAction(): Action
    {
        $allowed = fn (ChargebackAlert $record): bool => self::authorized('feedback', $record) && self::outcomes($record) !== []
            && $record->remote_feedback_at === null && ! $record->evidence_conflict
            && ! $record->has_open_or_submitted_feedback;

        return Action::make('sendFeedback')->label(__('chargeback_alerts.send_feedback'))->authorize($allowed)->visible($allowed)
            ->modalDescription(__('chargeback_alerts.feedback_help'))
            ->schema([
                Select::make('outcome')->label(__('chargeback_alerts.outcome'))->options(fn (ChargebackAlert $record): array => self::outcomes($record))
                    ->required()->live()->afterStateUpdated(fn (Set $set) => $set('refund_status', null)),
                Select::make('refund_status')->label(__('chargeback_alerts.refund_status'))->required()->live()
                    ->options(fn (ChargebackAlert $record, Get $get): array => self::feedbackDriver($record)?->refundStatuses($record, (string) $get('outcome')) ?? []),
                TextInput::make('refund_amount')->label(__('chargeback_alerts.refund_amount'))->numeric()
                    ->visible(fn (Get $get): bool => $get('refund_status') === 'refunded')->required(),
                DateTimePicker::make('refund_time')->label(__('chargeback_alerts.refund_time'))->maxDate(fn () => now())
                    ->visible(fn (Get $get): bool => $get('refund_status') === 'refunded')->required(),
                TextInput::make('evidence_reference')->label(__('chargeback_alerts.refund_evidence'))->maxLength(120)
                    ->visible(fn (Get $get): bool => $get('refund_status') === 'refunded')->required(),
                Textarea::make('comments')->label(__('chargeback_alerts.note'))->maxLength(250),
            ])->action(function (ChargebackAlert $record, array $data, ChargebackFeedback $feedback): void {
                $feedback->request(self::actor(), $record->id, $data);
                Notification::make()->title(__('chargeback_alerts.feedback_queued'))->success()->send();
            });
    }

    /** @return array<string, string> */
    private static function outcomes(ChargebackAlert $record): array
    {
        $outcomes = self::feedbackDriver($record)?->outcomes($record) ?? [];

        return $record->order_id === null ? array_intersect_key($outcomes, ['notfound' => true]) : $outcomes;
    }

    private static function feedbackDriver(ChargebackAlert $record): ?SendsChargebackFeedback
    {
        $account = $record->account;
        $driver = app(ChargebackAlertDrivers::class)->find($account->provider);

        return $account->enabled && in_array($record->service, $account->services, true) && $driver instanceof SendsChargebackFeedback ? $driver : null;
    }

    private static function authorized(string $ability, ChargebackAlert $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(ChargebackAlertPolicy::class)->{$ability}($actor, $record);
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public static function getPages(): array
    {
        return ['index' => ManageChargebackAlerts::route('/')];
    }
}

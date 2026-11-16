<?php

namespace App\Filament\Pages;

use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\TelegramMessageTemplate;
use App\Services\TelegramNotificationRouter;
use App\Services\TelegramNotificationSettings;
use App\Support\OrderTelegramSummary;
use App\Support\TelegramNotificationKind;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class TelegramNotificationRoutes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected string $view = 'filament.pages.telegram-notification-routes';

    public string $notificationGroup = 'merchant';

    protected TelegramNotificationSettings $settings;

    protected TelegramMessageTemplate $templates;

    protected TelegramNotificationRouter $router;

    public function boot(TelegramNotificationSettings $settings, TelegramMessageTemplate $templates, TelegramNotificationRouter $router): void
    {
        $this->settings = $settings;
        $this->templates = $templates;
        $this->router = $router;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.telegram_routes.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return __('admin.telegram_routes.description');
    }

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return Filament::getCurrentPanel()?->getId() === 'admin'
            && $user instanceof User
            && User::query()->whereKey($user->id)->where('is_super_admin', true)->where('status', true)->exists();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function setNotificationGroup(string $group): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(in_array($group, ['merchant', 'channel'], true), 422);
        $this->notificationGroup = $group;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, array $filters): Collection => $this->notificationRecords($search, $filters))
            ->columns([
                TextColumn::make('label')->label(__('admin.telegram_routes.notification'))
                    ->description(fn (array $record): string => $record['description'])->wrap(),
                IconColumn::make('enabled')->label(__('admin.telegram_routes.enabled'))->boolean(),
                TextColumn::make('recipient')->label(__('admin.telegram_routes.recipient_rule'))->wrap(),
                TextColumn::make('strategy')->label(__('admin.telegram_routes.strategy'))->wrap(),
            ])
            ->searchable()
            ->paginated(false)
            ->filters([
                SelectFilter::make('enabled')->label(__('admin.telegram_routes.enabled'))
                    ->options(['1' => __('admin.telegram_routes.on'), '0' => __('admin.telegram_routes.off')]),
            ])
            ->recordActions([
                Action::make('edit')->label(__('admin.telegram_routes.edit'))->icon('heroicon-o-pencil-square')
                    ->authorize(fn (): bool => static::canAccess())
                    ->slideOver()
                    ->modalHeading(fn (array $record): string => $record['label'])
                    ->fillForm(function (array $record): array {
                        $kind = TelegramNotificationKind::from($record['kind']);
                        $data = $this->settings->get($kind);

                        if ($kind->hasOrderSummary() && blank($data['order_template'])) {
                            $data['order_template'] = OrderTelegramSummary::defaultTemplate();
                        }

                        return $data;
                    })
                    ->schema(fn (array $record): array => $this->editSchema(TelegramNotificationKind::from($record['kind'])))
                    ->action(function (array $record, array $data, Action $action): void {
                        abort_unless(static::canAccess(), 403);

                        try {
                            $this->settings->save(TelegramNotificationKind::from($record['kind']), $data);
                        } catch (ValidationException $exception) {
                            $messages = [];

                            foreach ($exception->errors() as $field => $errors) {
                                $messages['mountedActions.'.$action->getNestingIndex().'.data.'.$field] = $errors;
                            }

                            throw ValidationException::withMessages($messages);
                        }

                        $this->resetTable();
                        Notification::make()->success()->title(__('admin.telegram_routes.saved'))->send();
                    }),
                Action::make('recipient')->label(__('admin.telegram_routes.inspect_recipient'))->icon('heroicon-o-user')
                    ->authorize(fn (): bool => static::canAccess())
                    ->modalHeading(fn (array $record): string => $record['label'])
                    ->modalSubmitAction(false)
                    ->schema(fn (array $record): array => $this->recipientSchema(TelegramNotificationKind::from($record['kind']))),
                Action::make('reset')->label(__('admin.telegram_routes.reset'))->color('gray')
                    ->authorize(fn (): bool => static::canAccess())
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.telegram_routes.reset_description'))
                    ->action(function (array $record): void {
                        abort_unless(static::canAccess(), 403);
                        $kind = TelegramNotificationKind::from($record['kind']);
                        $this->settings->save($kind, $this->settings->defaults($kind));
                        $this->resetTable();
                        Notification::make()->success()->title(__('admin.telegram_routes.saved'))->send();
                    }),
            ]);
    }

    /** @return Collection<string, array<string, mixed>> */
    protected function notificationRecords(?string $search, array $filters): Collection
    {
        abort_unless(static::canAccess(), 403);

        return collect(TelegramNotificationKind::cases())
            ->filter(fn (TelegramNotificationKind $kind): bool => $kind->group() === $this->notificationGroup)
            ->mapWithKeys(function (TelegramNotificationKind $kind): array {
                $settings = $this->settings->get($kind);

                return [$kind->value => [
                    'kind' => $kind->value,
                    'label' => __('admin.telegram_routes.kinds.'.$kind->value.'.label'),
                    'description' => __('admin.telegram_routes.kinds.'.$kind->value.'.description'),
                    'enabled' => $settings['enabled'],
                    'recipient' => __('admin.telegram_routes.recipients.'.$kind->recipientRule()),
                    'strategy' => $this->strategySummary($kind, $settings),
                ]];
            })
            ->filter(fn (array $record): bool => blank($search) || mb_stripos($record['label'].' '.$record['description'], $search) !== false)
            ->filter(fn (array $record): bool => ! isset($filters['enabled']['value']) || $filters['enabled']['value'] === ''
                || $record['enabled'] === (bool) $filters['enabled']['value']);
    }

    /** @return list<Component> */
    private function editSchema(TelegramNotificationKind $kind): array
    {
        $fields = [Toggle::make('enabled')->label(__('admin.telegram_routes.enabled'))->required(),
            TextEntry::make('recipient_rule')->label(__('admin.telegram_routes.recipient_rule'))
                ->state(__('admin.telegram_routes.rule_descriptions.'.$kind->recipientRule())),
        ];

        foreach ($kind->strategyDefaults() as $key => $default) {
            $field = TextInput::make($key)->label(__('admin.telegram_routes.fields.'.$key))->required()->live(onBlur: true);

            if ($key === 'send_time') {
                $field->type('time')->rules(['date_format:H:i'])
                    ->helperText(__('admin.telegram_routes.timezone', ['timezone' => config('app.timezone')]));
            } else {
                $field->integer()->minValue(1)->maxValue(match ($key) {
                    'repeat_count' => 5, 'cooldown_minutes' => 10080, 'reminder_hours' => 720, 'unshipped_days' => 365,
                });
            }

            $fields[] = $field;
        }

        return [
            Section::make(__('admin.telegram_routes.basic'))->schema($fields),
            Section::make(__('admin.telegram_routes.message'))->schema([
                Textarea::make('template')->label(__('admin.telegram_routes.template'))->rows(8)->maxLength(1500)
                    ->placeholder($this->templates->defaultTemplate($kind))
                    ->helperText(__('admin.telegram_routes.template_help'))
                    ->live(onBlur: true),
                Select::make('insert_variable')->label(__('admin.telegram_routes.insert_variable'))
                    ->options(array_combine($kind->variables(), array_map(fn (string $variable): string => ':'.$variable, $kind->variables())))
                    ->dehydrated(false)->live()
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($kind): void {
                        if ($state !== null && in_array($state, $kind->variables(), true)) {
                            $template = $get('template') ?: $this->templates->defaultTemplate($kind);
                            $set('template', $template.' :'.$state);
                        }

                        $set('insert_variable', null);
                    }),
                ...($kind->hasOrderSummary() ? [
                    Textarea::make('order_template')->label(__('admin.telegram_routes.order_template'))
                        ->rows(7)->maxLength(OrderTelegramSummary::MAX_LENGTH)
                        ->helperText(__('admin.telegram_routes.order_template_help'))
                        ->live(onBlur: true),
                    Select::make('insert_order_variable')->label(__('admin.telegram_routes.insert_order_variable'))
                        ->options(array_combine(OrderTelegramSummary::variables(), array_map(fn (string $variable): string => ':'.$variable, OrderTelegramSummary::variables())))
                        ->dehydrated(false)->live()
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if ($state !== null && in_array($state, OrderTelegramSummary::variables(), true)) {
                                $template = $get('order_template') ?: OrderTelegramSummary::defaultTemplate();
                                $set('order_template', $template.' :'.$state);
                            }

                            $set('insert_order_variable', null);
                        }),
                ] : []),
                TextEntry::make('preview')->label(__('admin.telegram_routes.preview'))
                    ->state(fn (Get $get): HtmlString => $this->messagePreview($kind, (string) ($get('template') ?? ''), (string) ($get('order_template') ?? ''), $get('unshipped_days'))),
            ]),
        ];
    }

    private function messagePreview(TelegramNotificationKind $kind, string $template, string $orderTemplate, mixed $unshippedDays = null): HtmlString
    {
        $variables = $this->templates->sampleVariables($kind);

        if ($kind === TelegramNotificationKind::UnshippedOverdue && is_numeric($unshippedDays)) {
            $variables['hours'] = (string) ((int) $unshippedDays * 24);
        }

        $message = $this->templates->render($kind, $variables, $template);

        if ($kind->hasOrderSummary()) {
            $message .= "\n\n".$this->templates->renderOrderSummary($kind, [
                'order_no' => 'SYS-DEMO-001', 'merchant_order_no' => 'SHOP-DEMO-001', 'transaction_id' => 'TX-DEMO-001',
                'payment_method_name' => __('admin.telegram_routes.sample_channel'), 'paid_at' => '2026-11-16 10:00:00',
                'currency' => 'USD', 'amount' => '100.00',
            ], $orderTemplate);
        }

        return new HtmlString(nl2br($message));
    }

    /** @return list<Component> */
    private function recipientSchema(TelegramNotificationKind $kind): array
    {
        $isChannel = $kind->group() === 'channel';

        return [
            TextEntry::make('rule')->label(__('admin.telegram_routes.recipient_rule'))
                ->state(__('admin.telegram_routes.rule_descriptions.'.$kind->recipientRule())),
            Select::make('context_id')->label(__('admin.telegram_routes.'.($isChannel ? 'choose_channel' : 'choose_merchant')))
                ->searchable()->live()
                ->getSearchResultsUsing(function (string $search) use ($isChannel): array {
                    abort_unless(static::canAccess(), 403);
                    $query = $isChannel ? PaymentMethod::query() : Merchant::query();
                    $column = $isChannel ? 'method_name' : 'name';

                    return $query->where($column, 'like', '%'.$search.'%')->orderBy('id')->limit(30)->pluck($column, 'id')->all();
                })
                ->getOptionLabelUsing(function (mixed $value) use ($isChannel): ?string {
                    abort_unless(static::canAccess(), 403);

                    return $isChannel ? PaymentMethod::query()->find($value)?->method_name : Merchant::query()->find($value)?->name;
                }),
            TextEntry::make('resolved_recipient')->label(__('admin.telegram_routes.actual_recipient'))
                ->state(function (Get $get) use ($kind, $isChannel): string {
                    abort_unless(static::canAccess(), 403);

                    if (! $get('context_id')) {
                        return __('admin.telegram_routes.choose_context');
                    }

                    $recipient = $this->router->recipient($kind, $isChannel ? null : (int) $get('context_id'), $isChannel ? (int) $get('context_id') : null);

                    return ($recipient['name'] !== '' ? $recipient['name'].' · ' : '')
                        .__('admin.telegram_routes.recipient_statuses.'.$recipient['reason']);
                }),
        ];
    }

    /** @param array<string, bool|int|string> $settings */
    private function strategySummary(TelegramNotificationKind $kind, array $settings): string
    {
        return match ($kind) {
            TelegramNotificationKind::OrderPaid => __('admin.telegram_routes.repeat_summary', ['count' => $settings['repeat_count']]),
            TelegramNotificationKind::NoAvailablePaymentMethod, TelegramNotificationKind::DisputeReviewGatewayStatusIgnored => __('admin.telegram_routes.cooldown_summary', ['minutes' => $settings['cooldown_minutes']]),
            TelegramNotificationKind::OrderDisputeDueSoon => __('admin.telegram_routes.reminder_summary', ['hours' => $settings['reminder_hours']]),
            TelegramNotificationKind::UnshippedOverdue => __('admin.telegram_routes.unshipped_summary', ['days' => $settings['unshipped_days'], 'time' => $settings['send_time']]),
            default => __('admin.telegram_routes.on_event'),
        };
    }
}

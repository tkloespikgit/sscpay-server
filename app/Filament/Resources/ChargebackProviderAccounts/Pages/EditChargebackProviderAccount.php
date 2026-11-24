<?php

namespace App\Filament\Resources\ChargebackProviderAccounts\Pages;

use App\Filament\Resources\ChargebackProviderAccounts\ChargebackProviderAccountResource;
use App\Filament\Resources\ChargebackProviderAccounts\RelationManagers\BindingsRelationManager;
use App\Models\ChargebackChannelBinding;
use App\Models\ChargebackProviderAccount;
use App\Models\User;
use App\Services\ChargebackAlerts\ChargebackAccountConfiguration;
use App\Services\ChargebackAlerts\ChargebackAlertDrivers;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditChargebackProviderAccount extends EditRecord
{
    protected static string $resource = ChargebackProviderAccountResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['signing_config']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): ChargebackProviderAccount
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ChargebackAccountConfiguration::class)->save($actor, $data, (int) $record->getKey());
    }

    protected function afterSave(): void
    {
        unset($this->data['signing_config']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bindPaymentMethods')->label(__('chargeback_alerts.bind_methods'))->icon('heroicon-o-credit-card')
                ->authorize(fn (): bool => ChargebackProviderAccountResource::canEdit($this->record))
                ->modalWidth(Width::FiveExtraLarge)->modalSubmitAction(false)
                ->modalCancelActionLabel(__('admin.merchant.payment_authorization.close'))
                ->modalDescription(__('chargeback_alerts.bind_help'))
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('service')->label(__('chargeback_alerts.service'))->live()->required()->default(fn () => $this->record->services[0] ?? null)
                        ->options(fn (ChargebackAlertDrivers $drivers): array => array_intersect_key(
                            $drivers->find($this->record->provider)?->services() ?? [], array_flip($this->record->services),
                        ))
                        ->afterStateUpdated(function (Set $set): void {
                            $set('scope_key', '');
                            $set('reference_type', ChargebackChannelBinding::ARN);
                        }),
                    TextInput::make('scope_key')->label(__('chargeback_alerts.scope'))->required()->maxLength(255)->live(onBlur: true)
                        ->helperText(__('chargeback_alerts.scope_help')),
                    Select::make('reference_type')->label(__('chargeback_alerts.reference_type'))->default(ChargebackChannelBinding::ARN)->required()
                        ->options(function (Get $get, ChargebackAlertDrivers $drivers): array {
                            $types = $drivers->find($this->record->provider)?->referenceTypes((string) $get('service')) ?? [];

                            return array_combine($types, array_map(fn (string $type): string => __("chargeback_alerts.references.{$type}"), $types));
                        })->helperText(__('chargeback_alerts.reference_help')),
                    DateTimePicker::make('valid_from')->label(__('chargeback_alerts.valid_from'))->default(fn () => now()->toDateTimeString())
                        ->maxDate(fn () => now())->required(),
                    View::make('filament.resources.chargeback-provider-accounts.payment-method-bindings')->columnSpanFull()
                        ->viewData(fn (Get $get, ChargebackAccountConfiguration $config): array => $this->bindingPanel($config, $get('service'), $get('scope_key'))),
                ])->columns(2)),
        ];
    }

    public function setChargebackChannelBinding(int $paymentMethodId, bool $selected, ChargebackAccountConfiguration $config): void
    {
        $actor = auth()->user();
        $action = $this->getMountedAction();
        abort_unless($actor instanceof User && $action?->getName() === 'bindPaymentMethods', 403);

        try {
            $config->setBinding($actor, (int) $this->record->getKey(), $paymentMethodId, $selected, $action->getRawData());
            $this->resetErrorBag('chargebackBinding');
            $this->dispatch('chargeback-bindings-updated')->to(BindingsRelationManager::class);
        } catch (ValidationException $error) {
            $this->addError('chargebackBinding', $error->validator->errors()->first());
        }
    }

    /** @return array<string, mixed> */
    private function bindingPanel(ChargebackAccountConfiguration $config, mixed $service, mixed $scope): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && ChargebackProviderAccountResource::canEdit($this->record), 403);

        if (! is_string($service) || ! in_array($service, $this->record->services, true) || ! is_string($scope)
            || trim($scope) === '' || mb_strlen($scope) > 255) {
            return ['columns' => null];
        }

        return ['columns' => $config->columns($actor, (int) $this->record->getKey(), $service, $scope)];
    }
}

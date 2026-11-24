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
        unset($data['signing_config'], $data['services']);

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

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.chargeback-provider-accounts.services')->viewData(function (ChargebackAlertDrivers $drivers): array {
                $driver = $drivers->find($this->record->provider);

                return ['provider' => $driver?->label(), 'services' => $driver?->services() ?? [], 'account' => $this->record];
            }),
            $this->getFormContentComponent(),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    public function setChargebackServiceEnabled(string $service, bool $enabled, ChargebackAccountConfiguration $config): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && ChargebackProviderAccountResource::canEdit($this->record), 403);

        $this->record = $config->setServiceEnabled($actor, $this->record, $service, $enabled);
    }

    public function bindPaymentMethodsAction(): Action
    {
        return Action::make('bindPaymentMethods')->label(__('chargeback_alerts.bind_methods'))
            ->authorize(fn (): bool => ChargebackProviderAccountResource::canEdit($this->record))
            ->modalWidth(Width::FiveExtraLarge)->modalSubmitAction(false)
            ->modalCancelActionLabel(__('admin.merchant.payment_authorization.close'))
            ->modalHeading(fn (array $arguments, ChargebackAlertDrivers $drivers): string => __('chargeback_alerts.bind_product', ['service' => $drivers->find($this->record->provider)?->services()[$this->bindingService($arguments)] ?? '']))
            ->modalDescription(__('chargeback_alerts.bind_help'))
            ->schema(function (Schema $schema, array $arguments, ChargebackAlertDrivers $drivers): Schema {
                $service = $this->bindingService($arguments);
                $types = $drivers->find($this->record->provider)->referenceTypes($service);

                return $schema->components([
                    TextInput::make('scope_key')->label(__(match ($service) {
                        'ethoca' => 'chargeback_alerts.registered_descriptor', 'rdr' => 'chargeback_alerts.mid', default => 'chargeback_alerts.scope',
                    }))->required()->maxLength(255)->live(onBlur: true),
                    Select::make('reference_type')->label(__('chargeback_alerts.reference_type'))->default(ChargebackChannelBinding::ARN)->required()
                        ->options(array_combine($types, array_map(fn (string $type): string => __("chargeback_alerts.references.{$type}"), $types)))
                        ->helperText(__('chargeback_alerts.reference_help')),
                    DateTimePicker::make('valid_from')->label(__('chargeback_alerts.valid_from'))->default(fn () => now()->toDateTimeString())
                        ->maxDate(fn () => now())->required(),
                    View::make('filament.resources.chargeback-provider-accounts.payment-method-bindings')->columnSpanFull()
                        ->viewData(fn (Get $get, ChargebackAccountConfiguration $config): array => $this->bindingPanel($config, $service, $get('scope_key'))),
                ])->columns(2);
            });
    }

    public function setChargebackChannelBinding(int $paymentMethodId, bool $selected, ChargebackAccountConfiguration $config): void
    {
        $actor = auth()->user();
        $action = $this->getMountedAction();
        abort_unless($actor instanceof User && $action?->getName() === 'bindPaymentMethods', 403);

        try {
            $config->setBinding($actor, (int) $this->record->getKey(), $paymentMethodId, $selected, ['service' => $this->bindingService($action->getArguments())] + $action->getRawData());
            $this->resetErrorBag('chargebackBinding');
            $this->dispatch('chargeback-bindings-updated')->to(BindingsRelationManager::class);
        } catch (ValidationException $error) {
            $this->addError('chargebackBinding', $error->validator->errors()->first());
        }
    }

    /** @return array<string, mixed> */
    private function bindingPanel(ChargebackAccountConfiguration $config, string $service, mixed $scope): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && ChargebackProviderAccountResource::canEdit($this->record), 403);

        if (! is_string($scope) || trim($scope) === '' || mb_strlen($scope) > 255) {
            return ['columns' => null];
        }

        return ['columns' => $config->columns($actor, (int) $this->record->getKey(), $service, $scope)];
    }

    /** @param array<string, mixed> $arguments */
    private function bindingService(array $arguments): string
    {
        $service = $arguments['service'] ?? null;
        $services = app(ChargebackAlertDrivers::class)->find($this->record->provider)?->services() ?? [];
        abort_unless(is_string($service) && array_key_exists($service, $services), 422);

        return $service;
    }
}

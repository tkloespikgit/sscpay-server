<?php

namespace App\Filament\Resources\MerchantResource\RelationManagers;

use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\MerchantPaymentSettingsService;
use App\Support\Permissions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentSettingsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentSettings';

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('payment_finance.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $ownerRecord instanceof Merchant && $actor instanceof User
            && $actor->can(Permissions::MERCHANTS_MANAGE)
            && MerchantPaymentSettingsService::canManage($actor, $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        $fields = [Select::make('payment_method_id')
            ->label(__('admin.payment_method.model_label'))
            ->options(fn () => PaymentMethod::query()->forMerchant($this->getOwnerRecord()->id)->pluck('method_name', 'id'))
            ->searchable()
            ->required()
            ->disabled(fn (?MerchantPaymentSetting $record): bool => $record !== null)
            ->dehydrated()
            ->columnSpanFull()];

        foreach (MerchantPaymentSetting::FINANCIAL_FIELDS as $field) {
            $input = TextInput::make($field)
                ->label(__('payment_finance.'.$field))
                ->helperText(__('payment_finance.inherit_default'))
                ->placeholder(__('payment_finance.inherit_default'))
                ->numeric()->minValue(0)->nullable();

            if (in_array($field, ['fee_percent', 'reserve_percent'], true)) {
                $input->maxValue($field === 'fee_percent' ? 99.9999 : 100)->suffix('%')->rules(['decimal:0,4']);
            } elseif ($field === 'reserve_days') {
                $input->integer()->maxValue(36500)->suffix(__('payment_finance.days'));
            } else {
                $input->prefix('$')->maxValue('9999999999999.99')->rules(['decimal:0,2']);
            }

            $fields[] = $input;
        }

        return $schema->components($fields)->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->heading(__('payment_finance.title'))
            ->modelLabel(__('payment_finance.title'))
            ->description(__('payment_finance.description'))
            ->columns([
                TextColumn::make('paymentMethod.method_name')->label(__('admin.payment_method.model_label')),
                TextColumn::make('fee_percent')->label(__('payment_finance.fee_percent'))->suffix('%')->placeholder(__('payment_finance.default_value')),
                TextColumn::make('fee_fixed')->label(__('payment_finance.fee_fixed'))->money('USD')->placeholder(__('payment_finance.default_value')),
                TextColumn::make('refund_fee')->label(__('payment_finance.refund_fee'))->money('USD')->placeholder(__('payment_finance.default_value')),
                TextColumn::make('chargeback_fee')->label(__('payment_finance.chargeback_fee'))->money('USD')->placeholder(__('payment_finance.default_value')),
                TextColumn::make('reserve_percent')->label(__('payment_finance.reserve_percent'))->suffix('%')->placeholder(__('payment_finance.default_value')),
                TextColumn::make('reserve_days')->label(__('payment_finance.reserve_days'))->placeholder(__('payment_finance.default_value')),
            ])
            ->headerActions([
                CreateAction::make()->authorize(fn (): bool => $this->mayManage())
                    ->using(fn (array $data, MerchantPaymentSettingsService $service): MerchantPaymentSetting => $service->save(
                        auth()->user(), $this->getOwnerRecord(), (int) $data['payment_method_id'], $data,
                    )),
            ])
            ->recordActions([
                EditAction::make()->authorize(fn (): bool => $this->mayManage())
                    ->using(fn (MerchantPaymentSetting $record, array $data, MerchantPaymentSettingsService $service): MerchantPaymentSetting => $service->save(
                        auth()->user(), $this->getOwnerRecord(), (int) $record->payment_method_id, $data,
                    )),
                DeleteAction::make()->label(__('payment_finance.reset'))
                    ->authorize(fn (): bool => $this->mayManage())
                    ->using(fn (MerchantPaymentSetting $record, MerchantPaymentSettingsService $service): bool => $service->delete(
                        auth()->user(), $this->getOwnerRecord(), $record,
                    )),
            ]);
    }

    private function mayManage(): bool
    {
        return static::canViewForRecord($this->getOwnerRecord(), $this->pageClass);
    }
}

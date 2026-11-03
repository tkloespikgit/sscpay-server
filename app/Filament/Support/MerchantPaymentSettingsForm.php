<?php

namespace App\Filament\Support;

use App\Models\MerchantPaymentSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

class MerchantPaymentSettingsForm
{
    public static function defaultsSection(): Section
    {
        return Section::make(__('payment_finance.defaults_title'))
            ->description(__('payment_finance.defaults_description'))
            ->schema(self::fields(true))
            ->columns(2)
            ->columnSpanFull();
    }

    /** @return list<Component> */
    public static function overrideFields(): array
    {
        return self::fields(false);
    }

    /** @return list<Component> */
    private static function fields(bool $defaults): array
    {
        $fields = [];

        foreach (MerchantPaymentSetting::FINANCIAL_FIELDS as $field) {
            $input = TextInput::make($defaults ? 'default_'.$field : $field)
                ->label(__('payment_finance.'.$field))
                ->numeric()
                ->minValue(0)
                ->required($defaults)
                ->nullable(! $defaults)
                ->columnSpan(1);

            if ($defaults) {
                $input->default(0);
            } else {
                $input->helperText(__('payment_finance.inherit_default'))
                    ->placeholder(__('payment_finance.inherit_default'));
            }

            if (in_array($field, ['fee_percent', 'reserve_percent'], true)) {
                $input->maxValue($field === 'fee_percent' ? 99.9999 : 100)
                    ->suffix('%')
                    ->rules(['decimal:0,4']);
            } elseif ($field === 'reserve_days') {
                $input->integer()->maxValue(36500)->suffix(__('payment_finance.days'));
            } else {
                $input->prefix('$')->maxValue('9999999999999.99')->rules(['decimal:0,2']);
            }

            $fields[] = $input;
        }

        $mode = Select::make('settlement_mode')
            ->label(__('settlement.fields.mode'))
            ->options([
                'immediate' => __('settlement.modes.immediate'),
                'shipped' => __('settlement.modes.shipped'),
            ])
            ->in(['immediate', 'shipped'])
            ->required($defaults)
            ->nullable(! $defaults)
            ->columnSpan(1);
        $days = TextInput::make('settlement_days')
            ->label(__('settlement.fields.cycle_days'))
            ->integer()
            ->minValue(0)
            ->maxValue(36500)
            ->required($defaults)
            ->nullable(! $defaults)
            ->suffix(__('settlement.days'))
            ->columnSpan(1);

        if ($defaults) {
            $mode->default('immediate')->helperText(__('settlement.settings.mode_help'));
            $days->default(0)->helperText(__('settlement.settings.cycle_help'));
        } else {
            $mode->placeholder(__('payment_finance.merchant_default_value'))
                ->helperText(__('payment_finance.inherit_merchant_settlement'));
            $days->placeholder(__('payment_finance.merchant_default_value'))
                ->helperText(__('payment_finance.inherit_merchant_settlement'));
        }

        return [...$fields, $mode, $days];
    }
}

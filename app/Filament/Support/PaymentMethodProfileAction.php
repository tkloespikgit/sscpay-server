<?php

namespace App\Filament\Support;

use App\Models\PaymentMethod;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;

/**
 * 支付方式"信息附表"弹框：库存地址、公司/法人资料、供货商资料，
 * 跟渠道接入配置（domain/密钥等）完全无关，只是内部留档用，
 * 所以单独开一个弹框维护，不出现在普通编辑表单里，且仅超级管理员可见——
 * 商户管理员/运营角色不该看到公司法人、供货商联系方式这类内部信息。
 */
class PaymentMethodProfileAction
{
    public static function make(): Action
    {
        return Action::make('paymentMethodProfile')
            ->label(__('admin.payment_method_profile.label'))
            ->icon('heroicon-o-clipboard-document-list')
            ->color('gray')
            ->visible(fn () => (bool) auth()->user()?->is_super_admin)
            ->modalHeading(__('admin.payment_method_profile.modal_heading'))
            ->modalDescription(__('admin.payment_method_profile.modal_description'))
            ->fillForm(fn (PaymentMethod $record) => [
                'stock_addresses' => $record->profile?->stock_addresses ?? [],
                'company_name' => $record->profile?->company_name,
                'legal_representative_name' => $record->profile?->legal_representative_name,
                'legal_representative_phone' => $record->profile?->legal_representative_phone,
                'account_email' => $record->profile?->account_email,
                'company_address' => $record->profile?->company_address,
                'supplier_name' => $record->profile?->supplier_name,
                'supplier_phone' => $record->profile?->supplier_phone,
                'supplier_email' => $record->profile?->supplier_email,
                'supplier_address' => $record->profile?->supplier_address,
                'supplier_contact_person' => $record->profile?->supplier_contact_person,
            ])
            ->schema([
                Repeater::make('stock_addresses')
                    ->label(__('admin.payment_method_profile.fields.stock_addresses'))
                    ->simple(
                        TextInput::make('address')->required()->maxLength(500)
                    )
                    ->addActionLabel(__('admin.payment_method_profile.actions.add_stock_address'))
                    ->reorderable(false),

                Section::make(__('admin.payment_method_profile.fields.company_name'))->schema([
                    TextInput::make('company_name')
                        ->label(__('admin.payment_method_profile.fields.company_name'))
                        ->maxLength(255),
                    TextInput::make('company_address')
                        ->label(__('admin.payment_method_profile.fields.company_address'))
                        ->maxLength(500),
                    TextInput::make('legal_representative_name')
                        ->label(__('admin.payment_method_profile.fields.legal_representative_name'))
                        ->maxLength(100),
                    TextInput::make('legal_representative_phone')
                        ->label(__('admin.payment_method_profile.fields.legal_representative_phone'))
                        ->tel()
                        ->maxLength(50),
                    TextInput::make('account_email')
                        ->label(__('admin.payment_method_profile.fields.account_email'))
                        ->email()
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])->columns(2),

                Section::make(__('admin.payment_method_profile.fields.supplier_name'))->schema([
                    TextInput::make('supplier_name')
                        ->label(__('admin.payment_method_profile.fields.supplier_name'))
                        ->maxLength(255),
                    TextInput::make('supplier_contact_person')
                        ->label(__('admin.payment_method_profile.fields.supplier_contact_person'))
                        ->maxLength(100),
                    TextInput::make('supplier_phone')
                        ->label(__('admin.payment_method_profile.fields.supplier_phone'))
                        ->tel()
                        ->maxLength(50),
                    TextInput::make('supplier_email')
                        ->label(__('admin.payment_method_profile.fields.supplier_email'))
                        ->email()
                        ->maxLength(255),
                    TextInput::make('supplier_address')
                        ->label(__('admin.payment_method_profile.fields.supplier_address'))
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])->columns(2),
            ])
            ->action(function (array $data, PaymentMethod $record) {
                $record->profile()->updateOrCreate(
                    ['payment_method_id' => $record->id],
                    [
                        'stock_addresses' => $data['stock_addresses'] ?? [],
                        'company_name' => $data['company_name'] ?: null,
                        'legal_representative_name' => $data['legal_representative_name'] ?: null,
                        'legal_representative_phone' => $data['legal_representative_phone'] ?: null,
                        'account_email' => $data['account_email'] ?: null,
                        'company_address' => $data['company_address'] ?: null,
                        'supplier_name' => $data['supplier_name'] ?: null,
                        'supplier_phone' => $data['supplier_phone'] ?: null,
                        'supplier_email' => $data['supplier_email'] ?: null,
                        'supplier_address' => $data['supplier_address'] ?: null,
                        'supplier_contact_person' => $data['supplier_contact_person'] ?: null,
                    ]
                );

                Notification::make()
                    ->title(__('admin.payment_method_profile.saved'))
                    ->success()
                    ->send();
            });
    }
}

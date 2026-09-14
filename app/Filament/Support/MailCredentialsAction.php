<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * Application / PaymentMethod 共用的"邮件发送配置"弹框：发件人（sender_email/
 * sender_name）+ 自有 ESP 凭证（mail_driver/mail_credentials）四个字段打包一起
 * 编辑，不出现在创建/编辑主表单里——必须先建好 Application/PaymentMethod 记录，
 * 再单独用这个弹框配置，避免建记录的时候顺手填错凭证。
 *
 * 两边的 EditXxx 页面（EditApplication/EditPaymentMethod）各自在
 * getHeaderActions() 里塞一个 MailCredentialsAction::make()，字段/校验/落库
 * 逻辑完全复用这一份，不用各自维护一套。
 */
class MailCredentialsAction
{
    public static function make(): Action
    {
        return Action::make('mailCredentials')
            ->label(fn (Model $record) => filled($record->sender_email)
                ? __('admin.mail_credentials.summary', ['email' => $record->sender_email])
                : __('admin.mail_credentials.not_configured'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->modalHeading(__('admin.mail_credentials.modal_heading'))
            ->modalDescription(__('admin.mail_credentials.modal_description'))
            ->fillForm(fn (Model $record) => [
                'sender_email' => $record->sender_email,
                'sender_name' => $record->sender_name,
                'mail_driver' => $record->mail_driver,
                'mail_credentials' => $record->mail_credentials ?? [],
            ])
            ->schema([
                TextInput::make('sender_email')
                    ->label(__('admin.mail_credentials.fields.sender_email'))
                    ->email()
                    ->maxLength(255)
                    ->placeholder('notify@hat.com')
                    ->live(onBlur: true)
                    ->required(fn (Get $get) => filled($get('mail_driver'))),
                TextInput::make('sender_name')
                    ->label(__('admin.mail_credentials.fields.sender_name'))
                    ->maxLength(255)
                    ->placeholder('Hat Shop Support'),
                Select::make('mail_driver')
                    ->label(__('admin.mail_credentials.fields.mail_driver'))
                    ->helperText(__('admin.mail_credentials.help.mail_driver'))
                    ->options(['postmark' => 'Postmark', 'ses' => 'Amazon SES', 'smtp' => 'SMTP'])
                    ->native(false)
                    ->live()
                    ->placeholder(__('admin.mail_credentials.placeholders.mail_driver_none'))
                    // 切换驱动后旧驱动残留的凭证字段没有意义（形状都不一样），
                    // 清空整个 mail_credentials，避免留一堆用不上的密文垃圾。
                    ->afterStateUpdated(fn (Set $set) => $set('mail_credentials', []))
                    ->columnSpanFull(),

                TextInput::make('mail_credentials.token')
                    ->label(__('admin.mail_credentials.fields.token'))
                    ->helperText(__('admin.mail_credentials.help.mail_credentials_postmark'))
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get) => $get('mail_driver') === 'postmark')
                    ->required(fn (Get $get) => $get('mail_driver') === 'postmark')
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('mail_credentials.key')
                    ->label(__('admin.mail_credentials.fields.ses_key'))
                    ->visible(fn (Get $get) => $get('mail_driver') === 'ses')
                    ->required(fn (Get $get) => $get('mail_driver') === 'ses')
                    ->maxLength(255),
                TextInput::make('mail_credentials.secret')
                    ->label(__('admin.mail_credentials.fields.ses_secret'))
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get) => $get('mail_driver') === 'ses')
                    ->required(fn (Get $get) => $get('mail_driver') === 'ses')
                    ->maxLength(255),
                TextInput::make('mail_credentials.region')
                    ->label(__('admin.mail_credentials.fields.ses_region'))
                    ->helperText(__('admin.mail_credentials.help.mail_credentials_ses'))
                    ->default('us-east-1')
                    ->visible(fn (Get $get) => $get('mail_driver') === 'ses')
                    ->maxLength(50)
                    ->columnSpanFull(),

                TextInput::make('mail_credentials.host')
                    ->label(__('admin.mail_credentials.fields.smtp_host'))
                    ->placeholder('smtp.example.com')
                    ->visible(fn (Get $get) => $get('mail_driver') === 'smtp')
                    ->required(fn (Get $get) => $get('mail_driver') === 'smtp')
                    ->maxLength(255),
                TextInput::make('mail_credentials.port')
                    ->label(__('admin.mail_credentials.fields.smtp_port'))
                    ->numeric()
                    ->default(587)
                    ->visible(fn (Get $get) => $get('mail_driver') === 'smtp'),
                TextInput::make('mail_credentials.username')
                    ->label(__('admin.mail_credentials.fields.smtp_username'))
                    ->visible(fn (Get $get) => $get('mail_driver') === 'smtp')
                    ->maxLength(255),
                TextInput::make('mail_credentials.password')
                    ->label(__('admin.mail_credentials.fields.smtp_password'))
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get) => $get('mail_driver') === 'smtp')
                    ->maxLength(255),
                Select::make('mail_credentials.encryption')
                    ->label(__('admin.mail_credentials.fields.smtp_encryption'))
                    ->helperText(__('admin.mail_credentials.help.mail_credentials_smtp'))
                    ->options(['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL'])
                    ->native(false)
                    ->placeholder(__('admin.mail_credentials.placeholders.smtp_encryption_auto'))
                    ->visible(fn (Get $get) => $get('mail_driver') === 'smtp')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, Model $record) {
                $record->update([
                    'sender_email' => $data['sender_email'] ?: null,
                    'sender_name' => $data['sender_name'] ?: null,
                    'mail_driver' => $data['mail_driver'] ?: null,
                    'mail_credentials' => $data['mail_credentials'] ?? [],
                ]);

                Notification::make()
                    ->title(__('admin.mail_credentials.saved'))
                    ->success()
                    ->send();
            });
    }
}

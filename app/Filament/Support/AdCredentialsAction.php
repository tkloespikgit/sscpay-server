<?php

namespace App\Filament\Support;

use App\Models\Application;
use App\Services\AdConversionService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

/**
 * Application 的"广告转化 API 凭证"弹框：按平台（meta/google/tiktok）分组填写
 * Meta Conversions API / Google Ads / TikTok Events API 各自所需的凭证，
 * 落库到 applications.ad_platform_credentials（encrypted:array，见 Application 模型）。
 *
 * 只有这里配置了凭证、且下单时 CreateOrderRequest.ad_params 也传了对应平台参数，
 * AdConversionService 才会在支付成功后真正调用该平台的转化 API（见该类顶部注释），
 * 与订单锁定的支付方式是否允许返回源站无关。
 */
class AdCredentialsAction
{
    public static function make(): Action
    {
        return Action::make('adCredentials')
            ->label(fn (Application $record) => self::summary($record))
            ->icon('heroicon-o-megaphone')
            ->color('gray')
            ->modalHeading(__('admin.ad_credentials.modal_heading'))
            ->modalDescription(__('admin.ad_credentials.modal_description'))
            ->fillForm(fn (Application $record) => [
                'ad_platform_credentials' => $record->ad_platform_credentials ?? [],
            ])
            ->schema([
                Section::make(__('admin.ad_credentials.sections.meta'))->schema([
                    TextInput::make('ad_platform_credentials.meta.pixel_id')
                        ->label(__('admin.ad_credentials.fields.meta_pixel_id'))
                        ->maxLength(64),
                    TextInput::make('ad_platform_credentials.meta.access_token')
                        ->label(__('admin.ad_credentials.fields.meta_access_token'))
                        ->password()
                        ->revealable()
                        ->maxLength(500),
                    TextInput::make('ad_platform_credentials.meta.test_event_code')
                        ->label(__('admin.ad_credentials.fields.meta_test_event_code'))
                        ->helperText(__('admin.ad_credentials.help.meta_test_event_code'))
                        ->maxLength(64)
                        ->columnSpanFull(),
                    TextInput::make('ad_platform_credentials.meta.event_name')
                        ->label(__('admin.ad_credentials.fields.meta_event_name'))
                        ->helperText(__('admin.ad_credentials.help.meta_event_name'))
                        ->placeholder('Purchase')
                        ->maxLength(64)
                        ->columnSpanFull(),
                ])->columns(2),

                Section::make(__('admin.ad_credentials.sections.google'))->schema([
                    TextInput::make('ad_platform_credentials.google.customer_id')
                        ->label(__('admin.ad_credentials.fields.google_customer_id'))
                        ->helperText(__('admin.ad_credentials.help.google_customer_id'))
                        ->maxLength(32),
                    TextInput::make('ad_platform_credentials.google.conversion_action_id')
                        ->label(__('admin.ad_credentials.fields.google_conversion_action_id'))
                        ->maxLength(64),
                    TextInput::make('ad_platform_credentials.google.developer_token')
                        ->label(__('admin.ad_credentials.fields.google_developer_token'))
                        ->password()
                        ->revealable()
                        ->maxLength(255),
                    TextInput::make('ad_platform_credentials.google.login_customer_id')
                        ->label(__('admin.ad_credentials.fields.google_login_customer_id'))
                        ->helperText(__('admin.ad_credentials.help.google_login_customer_id'))
                        ->maxLength(32),
                    TextInput::make('ad_platform_credentials.google.client_id')
                        ->label(__('admin.ad_credentials.fields.google_client_id'))
                        ->maxLength(255),
                    TextInput::make('ad_platform_credentials.google.client_secret')
                        ->label(__('admin.ad_credentials.fields.google_client_secret'))
                        ->password()
                        ->revealable()
                        ->maxLength(255),
                    TextInput::make('ad_platform_credentials.google.refresh_token')
                        ->label(__('admin.ad_credentials.fields.google_refresh_token'))
                        ->helperText(__('admin.ad_credentials.help.google_refresh_token'))
                        ->password()
                        ->revealable()
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])->columns(2),

                Section::make(__('admin.ad_credentials.sections.tiktok'))->schema([
                    TextInput::make('ad_platform_credentials.tiktok.pixel_code')
                        ->label(__('admin.ad_credentials.fields.tiktok_pixel_code'))
                        ->maxLength(64),
                    TextInput::make('ad_platform_credentials.tiktok.access_token')
                        ->label(__('admin.ad_credentials.fields.tiktok_access_token'))
                        ->password()
                        ->revealable()
                        ->maxLength(500),
                    TextInput::make('ad_platform_credentials.tiktok.event_name')
                        ->label(__('admin.ad_credentials.fields.tiktok_event_name'))
                        ->helperText(__('admin.ad_credentials.help.tiktok_event_name'))
                        ->placeholder('CompletePayment')
                        ->maxLength(64)
                        ->columnSpanFull(),
                ])->columns(2),
            ])
            ->action(function (array $data, Application $record) {
                $record->update([
                    'ad_platform_credentials' => self::pruneEmptyPlatforms($data['ad_platform_credentials'] ?? []),
                ]);

                Notification::make()
                    ->title(__('admin.ad_credentials.saved'))
                    ->success()
                    ->send();
            });
    }

    /**
     * 表单里空着不填的平台会提交一个全是空字符串的数组，落库前过滤掉——
     * 保持 adCredentialsFor() 的"未配置该平台"判定（空数组 -> null）准确。
     */
    private static function pruneEmptyPlatforms(array $credentials): array
    {
        return collect($credentials)
            ->map(fn (array $fields) => array_filter($fields, fn ($value) => filled($value)))
            ->filter(fn (array $fields) => $fields !== [])
            ->all();
    }

    private static function summary(Application $record): string
    {
        $configured = collect(AdConversionService::SUPPORTED_PLATFORMS)
            ->filter(fn (string $platform) => (bool) $record->adCredentialsFor($platform))
            ->map(fn (string $platform) => Str::ucfirst($platform));

        return $configured->isEmpty()
            ? __('admin.ad_credentials.not_configured')
            : __('admin.ad_credentials.summary', ['platforms' => $configured->implode(', ')]);
    }
}

<?php

namespace App\Filament\Resources\PaymentMethodResource\Pages;

use App\Filament\Resources\PaymentMethodResource;
use App\Models\PaymentMethodConfigMap;
use App\Services\PaymentGateway\Exceptions\PaymentGatewayException;
use App\Services\PaymentGateway\PaymentGatewayService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            $this->getSyncGatewayConfigAction(),
        ];
    }

    /**
     * 同步支付配置：把当前表单里的网关配置提交到站点的支付插件
     * （POST /gateway-config，用站点的 WooCommerce REST API 密钥 Consumer Key / Secret 做 Basic Auth），
     * 成功后把返回的 data.config_id 回填到"支付配置 ID"。
     * 同一个 config_key 重复同步是幂等覆盖（插件侧保证）。
     */
    protected function getSyncGatewayConfigAction(): Action
    {
        return Action::make('syncGatewayConfig')
            ->label(__('admin.payment_method.actions.sync_gateway_config'))
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('admin.payment_method.actions.sync_gateway_config_heading'))
            ->modalDescription(__('admin.payment_method.actions.sync_gateway_config_desc'))
            ->action(function () {
                // 取当前表单状态（含未保存的修改），同时触发必填项校验。
                $data = $this->form->getState();

                $configMap = filled($data['config_map_id'] ?? null)
                    ? PaymentMethodConfigMap::find($data['config_map_id'])
                    : null;

                if (! $configMap || blank($configMap->payment_config_tag)) {
                    Notification::make()
                        ->warning()
                        ->title(__('admin.payment_method.actions.sync_gateway_config_missing_tag'))
                        ->send();

                    return;
                }

                if (blank($data['domain_client_id'] ?? null) || blank($data['domain_client_sk'] ?? null)) {
                    Notification::make()
                        ->warning()
                        ->title(__('admin.payment_method.actions.sync_gateway_config_missing_credentials'))
                        ->send();

                    return;
                }

                // config_key 插件要求字母数字下划线中划线且 ≤64 字符；
                // 按 支付方式ID + 支付类型标签 生成，重复同步时幂等覆盖旧配置。
                $configKey = Str::limit(
                    preg_replace('/[^A-Za-z0-9_-]/', '_', 'pm_'.$this->record->id.'_'.$configMap->payment_config_tag),
                    64,
                    ''
                );

                try {
                    $result = app(PaymentGatewayService::class)
                        ->withConnection(
                            rtrim((string) $data['domain'], '/').'/wp-json/payment-plugin/v1',
                            (string) $data['domain_client_id'],
                            (string) $data['domain_client_sk'],
                        )
                        ->registerGatewayConfig(
                            $configKey,
                            $configMap->payment_config_tag,
                            (array) ($data['config'] ?? []),
                        );
                } catch (PaymentGatewayException $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('admin.payment_method.actions.sync_gateway_config_failed'))
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                $configId = (string) ($result['config_id'] ?? '');

                // 直接落库保存，并同步表单状态，让"支付配置 ID"字段立即展示最新值。
                $this->record->update(['payment_config_id' => $configId]);
                $this->data['payment_config_id'] = $configId;

                Notification::make()
                    ->success()
                    ->title(__('admin.payment_method.actions.sync_gateway_config_success'))
                    ->send();
            });
    }
}

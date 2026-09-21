<?php

namespace App\Filament\Resources\PaymentMethodResource\Pages;

use App\Filament\Resources\PaymentMethodResource;
use App\Filament\Support\MailCredentialsAction;
use App\Filament\Support\PaymentMethodProfileAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    /**
     * 保存前先记下当前分配的商户 ID，保存后跟最新分配名单比对，把"被移除的商户"
     * 名下支付组里对这条支付方式的引用一并摘除——否则取消分配后，那些商户已经
     * 建好的支付组还是会继续路由到这条支付方式收单，跟"取消分配"的预期不符。
     *
     * @var array<int>
     */
    protected array $previousAssignedMerchantIds = [];

    protected function beforeSave(): void
    {
        $this->previousAssignedMerchantIds = $this->record->assignedMerchants()->pluck('merchants.id')->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            PaymentMethodProfileAction::make(),
            MailCredentialsAction::make(),
            $this->getDetachFromAllPaymentGroupsAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * 把这条支付方式整体从所有商户的支付组里摘除，用于下线一个支付方式时确保
     * 不再被任何支付组当作候选通道路由（见 PaymentService::resolvePaymentMethod()）。
     */
    protected function getDetachFromAllPaymentGroupsAction(): Action
    {
        return Action::make('detachFromAllPaymentGroups')
            ->label(__('admin.payment_method.actions.detach_all_groups'))
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('admin.payment_method.actions.detach_all_groups_heading'))
            ->modalDescription(__('admin.payment_method.actions.detach_all_groups_desc'))
            ->visible(fn () => PaymentMethodResource::canManageRecord($this->record))
            ->action(function () {
                $count = $this->record->paymentGroups()->count();
                $this->record->paymentGroups()->detach();

                Notification::make()
                    ->success()
                    ->title(__('admin.payment_method.actions.detach_all_groups_success', ['count' => $count]))
                    ->send();
            });
    }

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            $this->getSyncGatewayConfigAction(),
        ];
    }

    /**
     * 保存后自动把网关配置同步到电商网站，不再依赖手动点击下方的同步按钮；
     * 同步成功/失败的提示取代 Filament 默认的"已保存"提示。
     */
    protected function afterSave(): void
    {
        PaymentMethodResource::syncGatewayConfigAndNotify($this->record);

        // 系统级改成商户自有：表单里的"分配商户"字段这时不会提交（visible/dehydrated 都关了），
        // 中间表里的旧分配会原样残留，其它商户依旧能通过 assignedMerchants 看到、并在支付组里
        // 路由到这条支付方式。这里把分配整体清掉，并视同"全部取消分配"处理支付组引用
        // （新归属商户自己的支付组除外——它现在是这条支付方式的主人）。
        if (! $this->record->isSystemLevel() && ! empty($this->previousAssignedMerchantIds)) {
            $this->record->assignedMerchants()->detach();

            $removedMerchantIds = array_diff($this->previousAssignedMerchantIds, [$this->record->merchant_id]);

            if (! empty($removedMerchantIds)) {
                PaymentMethodResource::detachFromGroupsOfMerchants($this->record, $removedMerchantIds);
            }

            return;
        }

        $currentAssignedMerchantIds = $this->record->assignedMerchants()->pluck('merchants.id')->all();
        $removedMerchantIds = array_diff($this->previousAssignedMerchantIds, $currentAssignedMerchantIds);

        if (! empty($removedMerchantIds)) {
            PaymentMethodResource::detachFromGroupsOfMerchants($this->record, $removedMerchantIds);
        }
    }

    protected function getSavedNotification(): ?Notification
    {
        return null;
    }

    /**
     * 同步支付配置：把当前表单里的网关配置提交到站点的支付插件
     * （POST /gateway-config，用站点的 WooCommerce REST API 密钥 Consumer Key / Secret 做 Basic Auth），
     * 成功后把返回的 data.config_id 回填到"支付配置 ID"。
     * 同一个 config_key 重复同步是幂等覆盖（插件侧保证）。
     * 与保存后的自动同步不同，这个按钮用的是未保存的表单状态，方便先试后存。
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

                $result = PaymentMethodResource::syncGatewayConfigFromData($this->record, $data);

                if (! $result['success']) {
                    match ($result['reason']) {
                        'missing_tag' => Notification::make()
                            ->warning()
                            ->title(__('admin.payment_method.actions.sync_gateway_config_missing_tag'))
                            ->send(),
                        'missing_credentials' => Notification::make()
                            ->warning()
                            ->title(__('admin.payment_method.actions.sync_gateway_config_missing_credentials'))
                            ->send(),
                        default => Notification::make()
                            ->danger()
                            ->title(__('admin.payment_method.actions.sync_gateway_config_failed'))
                            ->body($result['message'] ?? null)
                            ->send(),
                    };

                    return;
                }

                // 同步表单状态，让"支付配置 ID"字段（record 上的隐藏字段）立即展示最新值。
                $this->data['payment_config_id'] = $result['config_id'];

                Notification::make()
                    ->success()
                    ->title(__('admin.payment_method.actions.sync_gateway_config_success'))
                    ->send();
            });
    }
}

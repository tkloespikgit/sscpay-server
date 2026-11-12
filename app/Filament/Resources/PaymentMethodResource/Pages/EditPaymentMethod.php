<?php

namespace App\Filament\Resources\PaymentMethodResource\Pages;

use App\Filament\Resources\PaymentMethodResource;
use App\Filament\Support\GeneratePurchaseOrderAction;
use App\Filament\Support\PaymentMethodProfileAction;
use App\Services\PaymentMethodMerchantAssignmentService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    #[Locked]
    public bool $initialIsActive = false;

    protected function afterFill(): void
    {
        $this->initialIsActive = (bool) $this->record->is_active;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('is_active', $data) && (bool) $data['is_active'] === $this->initialIsActive) {
            unset($data['is_active']);
        }

        return PaymentMethodResource::authorizedFinancialData($data, $this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $this->record = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());

            return parent::handleRecordUpdate($this->record, $data);
        }, 3);
    }

    protected function getHeaderActions(): array
    {
        return [
            GeneratePurchaseOrderAction::make(),
            PaymentMethodProfileAction::make(),
            $this->getDetachFromAllPaymentGroupsAction(),
            DeleteAction::make(),
        ];
    }

    public function setMerchantAuthorization(int $merchantId, bool $authorized, PaymentMethodMerchantAssignmentService $service): void
    {
        $service->setAssignment(auth()->user(), $this->record, $merchantId, $authorized);
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
        return [...parent::getFormActions(), $this->getSyncGatewayConfigAction()];
    }

    /**
     * 保存后自动把网关配置同步到电商网站，不再依赖手动点击下方的同步按钮；
     * 同步成功/失败的提示取代 Filament 默认的"已保存"提示。
     */
    protected function afterSave(): void
    {
        $this->initialIsActive = (bool) $this->record->is_active;
        $this->data['is_active'] = $this->initialIsActive;

        PaymentMethodResource::syncGatewayConfigAndNotify($this->record);

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
            ->visible(fn (): bool => $this->record->payment_type === 'fiat')
            ->label(__('admin.payment_method.actions.sync_gateway_config'))
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('admin.payment_method.actions.sync_gateway_config_heading'))
            ->modalDescription(__('admin.payment_method.actions.sync_gateway_config_desc'))
            ->action(function () {
                abort_unless($this->record->payment_type === 'fiat' && PaymentMethodResource::canManageRecord($this->record), 403);
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

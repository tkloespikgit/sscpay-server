<?php

namespace App\Filament\Resources\PaymentMethodResource\Pages;

use App\Filament\Resources\PaymentMethodResource;
use App\Services\PaymentMethodMerchantAssignmentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentMethod extends CreateRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public function setMerchantAuthorization(int $merchantId, bool $authorized, PaymentMethodMerchantAssignmentService $service): void
    {
        abort_unless(blank($this->data['merchant_id'] ?? null)
            && $service->merchants(auth()->user())->contains('id', $merchantId), 403);

        $selectedIds = collect($this->data['assignedMerchants'] ?? []);
        $this->data['assignedMerchants'] = ($authorized ? $selectedIds->push($merchantId) : $selectedIds->reject(
            fn (mixed $selectedId): bool => (int) $selectedId === $merchantId,
        ))->unique()->values()->all();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PaymentMethodResource::authorizedFinancialData($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $merchantIds = $data['assignedMerchants'] ?? [];
        unset($data['assignedMerchants']);

        $record = parent::handleRecordCreation($data);

        if ($record->isSystemLevel()) {
            $service = app(PaymentMethodMerchantAssignmentService::class);

            foreach ($merchantIds as $merchantId) {
                $service->setAssignment(auth()->user(), $record, (int) $merchantId, true);
            }
        }

        return $record;
    }

    /**
     * 创建后自动把网关配置同步到电商网站，同步成功/失败的提示取代
     * Filament 默认的"已创建"提示。
     */
    protected function afterCreate(): void
    {
        PaymentMethodResource::syncGatewayConfigAndNotify($this->record);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}

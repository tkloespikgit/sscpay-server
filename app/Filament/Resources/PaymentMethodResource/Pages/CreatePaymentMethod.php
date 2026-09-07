<?php

namespace App\Filament\Resources\PaymentMethodResource\Pages;

use App\Filament\Resources\PaymentMethodResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentMethod extends CreateRecord
{
    protected static string $resource = PaymentMethodResource::class;

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

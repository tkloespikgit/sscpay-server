<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantResource;
use App\Services\MerchantPaymentSettingsService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMerchant extends EditRecord
{
    protected static string $resource = MerchantResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function afterSave(): void
    {
        app(MerchantPaymentSettingsService::class)->initializeMissingForMerchant((int) $this->record->getKey());
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(), // 软删除，关联数据保留（4.1 节）
        ];
    }
}

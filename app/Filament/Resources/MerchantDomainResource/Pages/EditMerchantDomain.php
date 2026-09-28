<?php

namespace App\Filament\Resources\MerchantDomainResource\Pages;

use App\Filament\Resources\MerchantDomainResource;
use App\Services\Checkout\CloudflareSaasService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditMerchantDomain extends EditRecord
{
    protected static string $resource = MerchantDomainResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (RuntimeException) {
            Notification::make()
                ->danger()
                ->title(__('admin.merchant_domain.errors.cloudflare_delete_failed'))
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(fn () => app(CloudflareSaasService::class)->delete($this->getRecord())),
        ];
    }
}

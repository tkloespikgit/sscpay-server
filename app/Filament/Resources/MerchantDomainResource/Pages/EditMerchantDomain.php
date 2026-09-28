<?php

namespace App\Filament\Resources\MerchantDomainResource\Pages;

use App\Filament\Resources\MerchantDomainResource;
use App\Models\MerchantDomain;
use App\Services\Checkout\CloudflareSaasService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
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
            Action::make('verify')
                ->label(__('admin.merchant_domain.actions.verify_refresh'))
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    abort_unless(MerchantDomainResource::canEdit($this->getRecord()), 403);

                    if (MerchantDomain::normalizeHost($this->data['host'] ?? '') !== $this->getRecord()->host) {
                        throw ValidationException::withMessages([
                            'data.host' => __('admin.merchant_domain.errors.save_host_first'),
                        ]);
                    }

                    MerchantDomainResource::verifyDomain($this->getRecord());
                    $this->getRecord()->refresh();
                }),
            DeleteAction::make()
                ->before(fn () => app(CloudflareSaasService::class)->delete($this->getRecord())),
        ];
    }
}

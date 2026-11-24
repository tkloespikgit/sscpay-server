<?php

namespace App\Filament\Resources\ChargebackProviderAccounts\Pages;

use App\Filament\Resources\ChargebackProviderAccounts\ChargebackProviderAccountResource;
use App\Models\ChargebackProviderAccount;
use App\Models\User;
use App\Services\ChargebackAlerts\ChargebackAccountConfiguration;
use Filament\Resources\Pages\CreateRecord;

class CreateChargebackProviderAccount extends CreateRecord
{
    protected static string $resource = ChargebackProviderAccountResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('edit');
    }

    protected function handleRecordCreation(array $data): ChargebackProviderAccount
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ChargebackAccountConfiguration::class)->save($actor, $data);
    }
}

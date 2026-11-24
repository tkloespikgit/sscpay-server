<?php

namespace App\Filament\Resources\ChargebackProviderAccounts\Pages;

use App\Filament\Resources\ChargebackProviderAccounts\ChargebackProviderAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChargebackProviderAccounts extends ListRecords
{
    protected static string $resource = ChargebackProviderAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

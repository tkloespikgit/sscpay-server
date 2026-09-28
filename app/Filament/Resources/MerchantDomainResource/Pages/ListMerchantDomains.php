<?php

namespace App\Filament\Resources\MerchantDomainResource\Pages;

use App\Filament\Resources\MerchantDomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMerchantDomains extends ListRecords
{
    protected static string $resource = MerchantDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

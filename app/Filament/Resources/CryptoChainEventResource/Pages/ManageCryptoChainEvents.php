<?php

namespace App\Filament\Resources\CryptoChainEventResource\Pages;

use App\Filament\Resources\CryptoChainEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCryptoChainEvents extends ManageRecords
{
    protected static string $resource = CryptoChainEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}

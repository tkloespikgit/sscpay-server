<?php

namespace App\Filament\Resources\CryptoLedgerEntryResource\Pages;

use App\Filament\Resources\CryptoLedgerEntryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCryptoLedgerEntries extends ManageRecords
{
    protected static string $resource = CryptoLedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}

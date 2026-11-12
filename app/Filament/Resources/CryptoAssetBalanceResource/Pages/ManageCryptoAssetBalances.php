<?php

namespace App\Filament\Resources\CryptoAssetBalanceResource\Pages;

use App\Filament\Resources\CryptoAssetBalanceResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCryptoAssetBalances extends ManageRecords
{
    protected static string $resource = CryptoAssetBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}

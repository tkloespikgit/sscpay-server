<?php

namespace App\Filament\Resources\CryptoWithdrawalResource\Pages;

use App\Filament\Resources\CryptoWithdrawalResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCryptoWithdrawals extends ManageRecords
{
    protected static string $resource = CryptoWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}

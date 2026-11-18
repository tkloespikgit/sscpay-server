<?php

namespace App\Filament\Resources\ChargebackOrders\Pages;

use App\Filament\Resources\ChargebackOrders\ChargebackOrderResource;
use Filament\Resources\Pages\ManageRecords;

class ManageChargebackOrders extends ManageRecords
{
    protected static string $resource = ChargebackOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

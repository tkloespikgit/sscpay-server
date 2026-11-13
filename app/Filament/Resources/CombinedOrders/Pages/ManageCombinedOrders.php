<?php

namespace App\Filament\Resources\CombinedOrders\Pages;

use App\Filament\Resources\CombinedOrders\CombinedOrderResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCombinedOrders extends ManageRecords
{
    protected static string $resource = CombinedOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

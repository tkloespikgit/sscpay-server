<?php

namespace App\Filament\Observer\Resources\OrderResource\Pages;

use App\Filament\Observer\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

<?php

namespace App\Filament\Resources\OrderSettlementResource\Pages;

use App\Filament\Resources\OrderSettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderSettlements extends ListRecords
{
    protected static string $resource = OrderSettlementResource::class;

    public function getSubheading(): ?string
    {
        return __('settlement.list_help');
    }
}

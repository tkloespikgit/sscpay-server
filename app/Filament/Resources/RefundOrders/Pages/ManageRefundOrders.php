<?php

namespace App\Filament\Resources\RefundOrders\Pages;

use App\Filament\Resources\RefundOrders\RefundOrderResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRefundOrders extends ManageRecords
{
    protected static string $resource = RefundOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

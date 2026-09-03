<?php

namespace App\Filament\Resources\PaymentGroupResource\Pages;

use App\Filament\Resources\PaymentGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentGroups extends ListRecords
{
    protected static string $resource = PaymentGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\PaymentMethodConfigMapResource\Pages;

use App\Filament\Resources\PaymentMethodConfigMapResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentMethodConfigMaps extends ListRecords
{
    protected static string $resource = PaymentMethodConfigMapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

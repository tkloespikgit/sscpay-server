<?php

namespace App\Filament\Resources\CheckoutLinkResource\Pages;

use App\Filament\Resources\CheckoutLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCheckoutLinks extends ListRecords
{
    protected static string $resource = CheckoutLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

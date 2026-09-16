<?php

namespace App\Filament\Resources\ObserverResource\Pages;

use App\Filament\Resources\ObserverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListObservers extends ListRecords
{
    protected static string $resource = ObserverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

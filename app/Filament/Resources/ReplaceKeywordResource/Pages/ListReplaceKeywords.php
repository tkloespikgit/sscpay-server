<?php

namespace App\Filament\Resources\ReplaceKeywordResource\Pages;

use App\Filament\Resources\ReplaceKeywordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReplaceKeywords extends ListRecords
{
    protected static string $resource = ReplaceKeywordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

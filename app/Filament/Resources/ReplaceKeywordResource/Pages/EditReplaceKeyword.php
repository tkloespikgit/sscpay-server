<?php

namespace App\Filament\Resources\ReplaceKeywordResource\Pages;

use App\Filament\Resources\ReplaceKeywordResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReplaceKeyword extends EditRecord
{
    protected static string $resource = ReplaceKeywordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

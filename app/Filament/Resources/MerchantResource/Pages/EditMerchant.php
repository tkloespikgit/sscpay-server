<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMerchant extends EditRecord
{
    protected static string $resource = MerchantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(), // 软删除，关联数据保留（4.1 节）
        ];
    }
}

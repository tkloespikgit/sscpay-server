<?php

namespace App\Filament\Resources\SystemConfigResource\Pages;

use App\Filament\Resources\SystemConfigResource;
use App\Filament\Support\SystemConfigForm;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;

class EditSystemConfig extends EditRecord
{
    protected static string $resource = SystemConfigResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SystemConfigForm::fill($data, $this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SystemConfigForm::save($data, $this->record);
    }

    /**
     * 保存后清除对应缓存，使配置立即生效。
     */
    protected function afterSave(): void
    {
        Cache::forget('system_config:'.$this->record->config_key);
    }
}

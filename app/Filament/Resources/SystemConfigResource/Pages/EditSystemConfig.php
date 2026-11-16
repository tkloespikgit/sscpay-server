<?php

namespace App\Filament\Resources\SystemConfigResource\Pages;

use App\Filament\Resources\SystemConfigResource;
use App\Filament\Support\SystemConfigForm;
use App\Services\CryptoRuntimeSettingsService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

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
     * 保存后清空密钥输入，避免明文留在后续页面状态中。
     */
    protected function afterSave(): void
    {
        $this->data['value_secret'] = null;
        $this->data['clear_secret'] = false;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(CryptoRuntimeSettingsService::class)->save(auth()->user(), $record, $data);
    }
}

<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Models\Application;
use Filament\Resources\Pages\CreateRecord;

class CreateApplication extends CreateRecord
{
    protected static string $resource = ApplicationResource::class;

    /**
     * 不用默认的 static::getModel()::create($data)，而是走
     * Application::createWithCredentials()，保证 app_id / api_key
     * 永远是系统生成的，商户在表单里没有机会（也不应该有机会）自己填。
     */
    protected function handleRecordCreation(array $data): Application
    {
        return Application::createWithCredentials($data);
    }
}

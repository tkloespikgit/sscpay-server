<?php

namespace App\Filament\Pages;

class LogisticsTrackingSettings extends ServiceSettings
{
    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        parent::mount();

        $this->redirect(ServiceSettings::getUrl());
    }

    public static function canAccess(array $parameters = []): bool
    {
        return static::canManageLogisticsTracking();
    }
}

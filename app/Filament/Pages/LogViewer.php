<?php

namespace App\Filament\Pages;

use AchyutN\FilamentLogViewer\LogTable;
use App\Filament\Support\RecentLogProvider;

class LogViewer extends LogTable
{
    protected static bool $isDiscovered = false;

    public function getSubheading(): string
    {
        return __('admin.log_viewer.scope_description', [
            'size' => RecentLogProvider::MAX_SOURCE_BYTES / 1024 / 1024,
            'count' => RecentLogProvider::MAX_ROWS,
        ]);
    }
}

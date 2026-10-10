<?php

namespace App\Filament\Observer\Resources\OrderResource\Pages;

use App\Filament\Observer\Resources\OrderResource;
use App\Services\ObserverOrderExportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportOrders')
                ->label(__('observer.orders.export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (ObserverOrderExportService $service) {
                    $observer = OrderResource::currentObserver();
                    abort_unless($observer?->status, 403);

                    return $service->download($observer, $this->getFilteredTableQuery());
                }),
        ];
    }
}

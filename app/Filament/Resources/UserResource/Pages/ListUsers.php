<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Pages\MerchantRolePermissions;
use App\Filament\Resources\UserResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rolePermissions')
                ->label(__('admin.role_permissions.title'))
                ->icon('heroicon-o-key')
                ->url(fn () => MerchantRolePermissions::getUrl(panel: 'admin'))
                ->visible(fn () => MerchantRolePermissions::canAccess()),
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Models\Application;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditApplication extends EditRecord
{
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerateApiKey')
                ->label(__('admin.application.actions.regenerate_api_key'))
                ->color('danger')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription(__('admin.application.actions.regenerate_api_key_confirm'))
                ->action(function () {
                    $credentials = Application::generateCredentials();

                    // app_id 也一并重新生成：如果只换 api_key、不换 app_id，
                    // 泄露的 app_id 仍然有效，攻击者只是暂时验签失败，
                    // 一起换掉才是真正的"作废旧凭证"。
                    $this->record->update([
                        'app_id' => $credentials['app_id'],
                        'api_key' => $credentials['api_key'],
                    ]);

                    Notification::make()
                        ->title(__('admin.application.actions.regenerate_api_key_success'))
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}

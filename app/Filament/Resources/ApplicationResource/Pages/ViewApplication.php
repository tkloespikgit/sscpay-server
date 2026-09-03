<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * 唯一能看到明文 api_key 的地方。api_key 用的是 Laravel 内置的
 * encrypted cast（可逆加密，不是单向哈希），$application->api_key
 * 读出来就已经是解密后的明文，这里直接展示即可，不需要额外解密逻辑。
 */
class ViewApplication extends ViewRecord
{
    protected static string $resource = ApplicationResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.application.sections.api_credentials'))
                ->description(__('admin.application.help.api_key'))
                ->schema([
                    TextEntry::make('app_id')
                        ->label(__('admin.application.fields.app_id'))
                        ->copyable(),
                    TextEntry::make('api_key')
                        ->label(__('admin.application.fields.api_key'))
                        ->copyable()
                        ->formatStateUsing(fn (string $state) => $state),
                ])
                ->columns(2),

            Section::make(__('admin.merchant.sections.basic_info'))->schema([
                TextEntry::make('name')->label(__('admin.application.fields.name')),
                TextEntry::make('website')->label(__('admin.application.fields.website'))->placeholder('—'),
                TextEntry::make('sender_email')->label(__('admin.application.fields.sender_email'))->placeholder('—'),
                TextEntry::make('sender_name')->label(__('admin.application.fields.sender_name'))->placeholder('—'),
                TextEntry::make('created_at')->label(__('admin.application.fields.created_at'))->dateTime(),
                IconEntry::make('status')
                    ->color(fn (int $state): string => match ($state) {
                        1 => 'success',
                        0 => 'danger',
                    })
            ])->columns(2),
        ]);
    }
}

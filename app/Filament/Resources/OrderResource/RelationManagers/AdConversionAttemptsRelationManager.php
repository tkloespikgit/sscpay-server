<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 广告转化通知（支付成功后告知 Meta/Google/TikTok 该订单已支付）的每一次尝试记录，
 * 结构与 OrderNotificationAttemptsRelationManager 对称，只读，按平台+尝试顺序排列。
 */
class AdConversionAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'adConversionAttempts';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.ad_conversion.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('attempt_number')
            ->columns([
                TextColumn::make('platform')->label(__('admin.ad_conversion.columns.platform'))->badge(),
                TextColumn::make('attempt_number')->label(__('admin.ad_conversion.columns.attempt_number'))->formatStateUsing(fn ($state, $record) => "{$state} / {$record->max_attempts}"),
                TextColumn::make('status')->label(__('admin.ad_conversion.columns.status'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'success' => 'success',
                        'failed' => 'warning',
                        'exhausted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('response_status_code')->label(__('admin.ad_conversion.columns.http_status')),
                TextColumn::make('duration_ms')->label(__('admin.ad_conversion.columns.duration_ms')),
                TextColumn::make('attempted_at')->label(__('admin.ad_conversion.columns.attempted_at'))->dateTime(),
                TextColumn::make('next_retry_at')->label(__('admin.ad_conversion.columns.next_retry_at'))->dateTime()->placeholder('—'),
            ])
            ->defaultSort('attempt_number')
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        TextEntry::make('request_payload')
                            ->label(__('admin.ad_conversion.view.request_payload'))
                            ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->columnSpanFull(),
                        TextEntry::make('response_body')->label(__('admin.ad_conversion.view.response_body'))->columnSpanFull(),
                        TextEntry::make('error_message')->label(__('admin.ad_conversion.view.error_message'))->columnSpanFull(),
                    ]),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}

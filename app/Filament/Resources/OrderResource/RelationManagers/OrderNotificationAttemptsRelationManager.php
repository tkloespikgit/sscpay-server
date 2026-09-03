<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 商户交易结果通知的每一次尝试记录（本轮新增需求：后台订单详情要能看到
 * 通知数据和商户端返回的 HTTP 状态、响应内容）。只读，按尝试顺序排列。
 */
class OrderNotificationAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'notificationAttempts';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.order_notification.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('attempt_number')
            ->columns([
                TextColumn::make('attempt_number')->label(__('admin.order_notification.columns.attempt_number'))->formatStateUsing(fn ($state, $record) => "{$state} / {$record->max_attempts}"),
                TextColumn::make('status')->label(__('admin.order_notification.columns.status'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'success' => 'success',
                        'failed' => 'warning',
                        'exhausted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('response_status_code')->label(__('admin.order_notification.columns.http_status')),
                TextColumn::make('duration_ms')->label(__('admin.order_notification.columns.duration_ms')),
                TextColumn::make('attempted_at')->label(__('admin.order_notification.columns.attempted_at'))->dateTime(),
                TextColumn::make('next_retry_at')->label(__('admin.order_notification.columns.next_retry_at'))->dateTime()->placeholder('—'),
            ])
            ->defaultSort('attempt_number')
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        TextEntry::make('notify_url')->label(__('admin.order_notification.view.notify_url')),
                        TextEntry::make('request_payload')
                            ->label(__('admin.order_notification.view.request_payload'))
                            ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->columnSpanFull(),
                        TextEntry::make('response_body')->label(__('admin.order_notification.view.response_body'))->columnSpanFull(),
                        TextEntry::make('error_message')->label(__('admin.order_notification.view.error_message'))->columnSpanFull(),
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

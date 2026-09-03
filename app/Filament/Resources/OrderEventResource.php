<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderEventResource\Pages;
use App\Models\OrderEvent;
use App\Support\Permissions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * 只读资源：order_events 镜像插件侧 /order-logs 接口返回的订单日志，本系统
 * 不产生也不允许在后台增删改（canCreate/canEdit/canDelete 全部 false）。
 * 支持按 order_no、level、occurred_at 筛选。
 */
class OrderEventResource extends Resource
{
    protected static ?string $model = OrderEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.order_management');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.order_event.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.order_event.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.order_event.model_label_plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_no')->label(__('admin.order_event.fields.order_no'))->searchable(),
                TextColumn::make('level')->label(__('admin.order_event.fields.level'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ERROR' => 'danger',
                        'WARNING' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('message')->label(__('admin.order_event.fields.message'))->limit(40),
                TextColumn::make('payment_method')->label(__('admin.order_event.fields.payment_method'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('wp_order_id')->label(__('admin.order_event.fields.wp_order_id'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('external_log_id')->label(__('admin.order_event.fields.external_log_id'))->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('occurred_at')->label(__('admin.order_event.fields.occurred_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('level')->label(__('admin.order_event.fields.level'))->options(
                    array_combine(OrderEvent::LEVELS, OrderEvent::LEVELS)
                ),
                Filter::make('order_no')
                    ->label(__('admin.order_event.fields.order_no'))
                    ->schema([
                        TextInput::make('order_no'),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['order_no'] ?? null,
                        fn ($q, $value) => $q->where('order_no', 'like', "%{$value}%")
                    )),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderEvents::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permissions::ORDER_EVENTS_VIEW);
    }
}

<?php

namespace App\Filament\Resources\CombinedOrders;

use App\Filament\Resources\CombinedOrders\Pages\ManageCombinedOrders;
use App\Filament\Resources\OrderResource;
use App\Models\CombinedOrder;
use App\Models\Order;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CombinedOrderResource extends Resource
{
    protected static ?string $model = CombinedOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'order_no';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.order_management');
    }

    public static function getModelLabel(): string
    {
        return __('combined.admin.title');
    }

    public static function getPluralModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('combined.admin.title'))->columns(2)->columnSpanFull()->schema([
                TextEntry::make('order_no')->label(__('admin.order.columns.order_no'))->copyable(),
                TextEntry::make('merchant_order_no')->label(__('combined.order'))->copyable(),
                TextEntry::make('status')->label(__('admin.order.columns.status'))
                    ->formatStateUsing(fn (string $state): string => __('combined.admin.states.'.$state)),
                TextEntry::make('currency')->label(__('admin.order.fields.currency')),
                TextEntry::make('amount')->label(__('combined.total')),
                TextEntry::make('paid_amount')->label(__('combined.paid')),
                TextEntry::make('remaining_amount')->label(__('combined.remaining')),
                TextEntry::make('paid_converted_amount')->label(__('combined.admin.paid_usd')),
                TextEntry::make('expires_at')->label(__('combined.admin.expires_at'))->dateTime(),
                TextEntry::make('last_error_code')->label(__('combined.admin.last_error'))
                    ->formatStateUsing(fn (?string $state): ?string => $state ? __('combined.errors.'.$state) : null),
                TextEntry::make('pay_url')->label(__('combined.admin.checkout'))->url(fn (CombinedOrder $record): string => $record->pay_url)
                    ->openUrlInNewTab()->copyable()->columnSpanFull(),
            ]),
            RepeatableEntry::make('children')->label(__('combined.history'))->columnSpanFull()->columns(3)->schema([
                TextEntry::make('merchant_order_no')->label(__('combined.order'))->copyable()
                    ->url(fn (Order $record): ?string => OrderResource::canView($record)
                        ? OrderResource::getUrl('view', ['record' => $record]) : null),
                TextEntry::make('amount')->label(__('combined.current')),
                TextEntry::make('status')->label(__('admin.order.columns.status'))
                    ->formatStateUsing(fn (string $state): string => __('admin.order.statuses.'.$state)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with(['merchant', 'application']))
            ->columns([
                TextColumn::make('merchant.name')->label(__('admin.order.columns.merchant_name'))
                    ->visible(fn (): bool => (bool) auth()->user()?->isPlatformStaff()),
                TextColumn::make('application.name')->label(__('admin.order.columns.application')),
                TextColumn::make('order_no')->label(__('admin.order.columns.order_no'))->searchable()->copyable(),
                TextColumn::make('merchant_order_no')->label(__('combined.order'))->searchable(),
                TextColumn::make('status')->label(__('admin.order.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('combined.admin.states.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success', 'review' => 'danger', 'partially_paid' => 'warning', default => 'gray',
                    }),
                TextColumn::make('currency')->label(__('admin.order.fields.currency')),
                TextColumn::make('amount')->label(__('combined.total')),
                TextColumn::make('paid_amount')->label(__('combined.paid')),
                TextColumn::make('remaining_amount')->label(__('combined.remaining')),
                TextColumn::make('created_at')->label(__('admin.order.columns.created_at'))->dateTime()->sortable(),
            ])->filters([
                SelectFilter::make('status')->label(__('admin.order.columns.status'))->options(__('combined.admin.states')),
            ])->recordActions([
                ViewAction::make()->authorize(fn (CombinedOrder $record): bool => static::canView($record)),
            ])->defaultSort('created_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->status && $user->can(Permissions::COMBINED_ORDERS_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && static::getEloquentQuery()->whereKey($record->id)->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageCombinedOrders::route('/')];
    }
}

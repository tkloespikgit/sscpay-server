<?php

namespace App\Filament\Resources\RefundOrders;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\RefundOrders\Pages\ManageRefundOrders;
use App\Filament\Support\OrderAfterSaleTable;
use App\Models\User;
use App\Services\OrderAfterSaleService;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RefundOrderResource extends OrderResource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?int $navigationSort = 11;

    public static function getModelLabel(): string
    {
        return __('admin.order.after_sale.refunds');
    }

    public static function getPluralModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function getEloquentQuery(): Builder
    {
        return app(OrderAfterSaleService::class)->refunds(parent::getEloquentQuery());
    }

    public static function table(Table $table): Table
    {
        return OrderAfterSaleTable::configure($table, chargebacks: false);
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->status && parent::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageRefundOrders::route('/')];
    }
}

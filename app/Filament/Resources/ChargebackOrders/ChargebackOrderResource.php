<?php

namespace App\Filament\Resources\ChargebackOrders;

use App\Filament\Resources\ChargebackOrders\Pages\ManageChargebackOrders;
use App\Filament\Resources\OrderResource;
use App\Filament\Support\OrderAfterSaleTable;
use App\Models\User;
use App\Services\OrderAfterSaleService;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ChargebackOrderResource extends OrderResource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 12;

    public static function getModelLabel(): string
    {
        return __('admin.order.after_sale.chargebacks');
    }

    public static function getPluralModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function getEloquentQuery(): Builder
    {
        return app(OrderAfterSaleService::class)->chargebacks(parent::getEloquentQuery());
    }

    public static function table(Table $table): Table
    {
        return OrderAfterSaleTable::configure($table, chargebacks: true);
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
        return ['index' => ManageChargebackOrders::route('/')];
    }
}

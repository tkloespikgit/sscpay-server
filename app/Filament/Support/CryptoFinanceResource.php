<?php

namespace App\Filament\Support;

use App\Support\CryptoFinanceAccess;
use App\Support\Permissions;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class CryptoFinanceResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string $cryptoLabel;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.crypto_finance');
    }

    public static function getModelLabel(): string
    {
        return __('crypto.finance.'.static::$cryptoLabel);
    }

    public static function getPluralModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function getEloquentQuery(): Builder
    {
        return CryptoFinanceAccess::scope(parent::getEloquentQuery())->with('merchant:id,name,status,owner_id');
    }

    public static function canViewAny(): bool
    {
        return CryptoFinanceAccess::allowed(CryptoFinanceAccess::user(), Permissions::CRYPTO_FINANCE_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && static::getEloquentQuery()->whereKey($record->getKey())->exists();
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

    public static function canDeleteAny(): bool
    {
        return false;
    }
}

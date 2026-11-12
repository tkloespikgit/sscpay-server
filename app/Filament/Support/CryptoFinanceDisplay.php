<?php

namespace App\Filament\Support;

use App\Models\CryptoChainEvent;
use App\Support\CryptoAmount;
use App\Support\CryptoAssetRegistry;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;

final class CryptoFinanceDisplay
{
    public static function amount(Model $record, ?string $units): string
    {
        if ($units === null) {
            return '—';
        }

        return CryptoAmount::format($units, app(CryptoAssetRegistry::class)->get($record->network.':'.$record->asset)['decimals']).' '.$record->asset;
    }

    public static function column(string $field, ?string $label = null): TextColumn
    {
        return TextColumn::make($field)->label(__('crypto.finance.fields.'.($label ?? $field)))
            ->formatStateUsing(fn (Model $record, ?string $state): string => self::amount($record, $state));
    }

    public static function entry(string $field, ?string $label = null): TextEntry
    {
        return TextEntry::make($field)->label(__('crypto.finance.fields.'.($label ?? $field)))
            ->formatStateUsing(fn (Model $record, ?string $state): string => self::amount($record, $state))->placeholder('—');
    }

    public static function network(): TextColumn
    {
        return TextColumn::make('network')->label(__('crypto.config.network'))->badge()
            ->formatStateUsing(fn (string $state): string => __('crypto.finance.networks.'.$state));
    }

    /** @return list<SelectFilter> */
    public static function filters(): array
    {
        return [
            SelectFilter::make('network')->label(__('crypto.config.network'))->options(__('crypto.finance.networks')),
            SelectFilter::make('asset')->label(__('crypto.config.asset'))->options(['BTC' => 'BTC', 'LTC' => 'LTC', 'ETH' => 'ETH', 'USDT' => 'USDT']),
        ];
    }

    public static function eventStatus(CryptoChainEvent $event): string
    {
        return match (true) {
            $event->orphaned_at !== null => 'orphaned',
            $event->verified_at === null => 'unverified',
            $event->confirmed_at === null => 'confirming',
            default => 'confirmed',
        };
    }
}

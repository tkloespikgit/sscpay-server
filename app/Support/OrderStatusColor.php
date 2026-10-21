<?php

namespace App\Support;

use App\Models\SystemConfig;
use Filament\Support\Facades\FilamentColor;

class OrderStatusColor
{
    public const CONFIG_KEY = 'order.status_colors';

    public const DEFAULT_COLORS = [
        'pending' => 'gray',
        'paid' => 'info',
        'shipped' => 'warning',
        'completed' => 'success',
        'cancelled' => 'danger',
        'failed' => 'danger',
        'expired' => 'danger',
        'disputing' => 'warning',
        'dispute_review' => 'warning',
        'partially_refunded' => 'warning',
        'refunded' => 'danger',
        'chargeback' => 'danger',
    ];

    public const ALLOWED_COLORS = ['gray', 'primary', 'success', 'info', 'warning', 'danger'];

    public static function get(string $status): string
    {
        $default = self::DEFAULT_COLORS[$status] ?? 'gray';

        if (! array_key_exists($status, self::DEFAULT_COLORS)) {
            return $default;
        }

        $color = SystemConfig::getArray(self::CONFIG_KEY)[$status] ?? null;

        return is_string($color) && in_array($color, self::ALLOWED_COLORS, true)
            ? $color
            : $default;
    }

    public static function cssColor(string $status): string
    {
        return FilamentColor::getColor(self::get($status))[600];
    }
}

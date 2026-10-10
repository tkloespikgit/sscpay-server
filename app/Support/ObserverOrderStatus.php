<?php

namespace App\Support;

class ObserverOrderStatus
{
    public const GROUPS = [
        'paid' => ['paid', 'shipped', 'completed'],
        'refunded' => ['refunded', 'partially_refunded'],
        'chargeback' => ['chargeback'],
        'disputing' => ['disputing', 'dispute_review'],
    ];

    public static function visibleStatuses(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function group(string $status): ?string
    {
        foreach (self::GROUPS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }

        return null;
    }

    public static function label(string $status): string
    {
        $group = self::group($status);

        return $group === null ? '' : __('observer.orders.statuses.'.$group);
    }

    public static function options(): array
    {
        return array_combine(array_keys(self::GROUPS), array_map(fn ($group) => __('observer.orders.statuses.'.$group), array_keys(self::GROUPS)));
    }
}

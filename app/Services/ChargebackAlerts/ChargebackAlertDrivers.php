<?php

namespace App\Services\ChargebackAlerts;

use Illuminate\Container\Attributes\Tag;

final readonly class ChargebackAlertDrivers
{
    public const TAG = 'chargeback-alert-drivers';

    /** @param iterable<ChargebackAlertDriver> $drivers */
    public function __construct(#[Tag(self::TAG)] private iterable $drivers) {}

    public function find(string $code): ?ChargebackAlertDriver
    {
        return $this->registered()[$code] ?? null;
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return array_map(static fn (ChargebackAlertDriver $driver): string => $driver->label(), $this->registered());
    }

    /** @return array<string, ChargebackAlertDriver> */
    private function registered(): array
    {
        $registered = [];
        foreach ($this->drivers as $driver) {
            $registered[$driver->code()] = $driver;
        }

        return $registered;
    }
}

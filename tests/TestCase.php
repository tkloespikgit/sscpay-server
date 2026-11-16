<?php

namespace Tests;

use App\Models\SystemConfig;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Configure the real database-backed settings while keeping ordinary framework config separate. */
    protected function setCryptoSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (str_starts_with($key, 'crypto.')) {
                SystemConfig::set($key, $value);
            } else {
                config([$key => $value]);
            }
        }
    }
}

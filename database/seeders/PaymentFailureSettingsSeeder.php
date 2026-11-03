<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentFailureSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('system_configs')->insertOrIgnore([
            'config_key' => PaymentMethod::MAX_FAILED_TIMES_CONFIG_KEY,
            'config_value' => (string) PaymentMethod::DEFAULT_MAX_FAILED_TIMES,
            'value_type' => SystemConfig::TYPE_NUMBER,
            'group' => 'payment',
            'description' => '支付通道连续建单失败停用阈值（正整数）；渠道未单独设置时沿用此值',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('system_config:'.PaymentMethod::MAX_FAILED_TIMES_CONFIG_KEY);
    }
}

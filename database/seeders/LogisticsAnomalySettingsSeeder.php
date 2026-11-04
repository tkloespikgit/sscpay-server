<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LogisticsAnomalySettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([
            'logistics_tracking.stale_days' => ['5', '物流轨迹无更新异常阈值（1–3650 整数天）；没有轨迹事件时从发货时间计算'],
            'logistics_tracking.no_delivery_days' => ['15', '物流发货后未签收异常阈值（1–3650 整数天，每天按 24 小时计算）'],
        ] as $key => [$value, $description]) {
            DB::table('system_configs')->insertOrIgnore([
                'config_key' => $key,
                'config_value' => $value,
                'value_type' => SystemConfig::TYPE_NUMBER,
                'group' => 'logistics',
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Cache::forget('system_config:'.$key);
        }
    }
}

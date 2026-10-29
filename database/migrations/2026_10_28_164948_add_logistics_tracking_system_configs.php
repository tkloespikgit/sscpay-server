<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('system_configs')->insertOrIgnore([
            [
                'config_key' => 'logistics_tracking.available_drivers',
                'config_value' => json_encode(['seventeen_track'], JSON_UNESCAPED_UNICODE),
                'value_type' => 'json',
                'group' => 'logistics',
                'description' => '物流跟踪可选驱动枚举（JSON 数组）。空=[] 表示平台关闭；商户在物流跟踪页从中选用具体驱动。当前支持 seventeen_track=17TRACK。',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Cache::forget('system_config:logistics_tracking.available_drivers');
        Cache::forget('system_config:logistics_tracking.driver');
        Cache::forget('system_config:logistics_tracking.seventeen_track.base_url');
    }

    public function down(): void
    {
        // 保留可能已由管理员修改的配置。
    }
};

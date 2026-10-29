<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 兼容已跑过旧版物流配置迁移的本地库：
 * - 商户表补 driver / base_url
 * - 系统配置改为 available_drivers JSON，删除全局 base_url / 旧 driver 单值键
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_logistics_tracking_settings')) {
            Schema::table('merchant_logistics_tracking_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('merchant_logistics_tracking_settings', 'driver')) {
                    $table->string('driver', 40)->nullable()->after('merchant_id')
                        ->comment('选用驱动（须在系统 available_drivers 枚举内）');
                }

                if (! Schema::hasColumn('merchant_logistics_tracking_settings', 'base_url')) {
                    $table->string('base_url', 255)->nullable()->after('driver')
                        ->comment('Provider API Base URL（可空，驱动默认）');
                }
            });

            $legacyDriver = trim((string) (DB::table('system_configs')
                ->where('config_key', 'logistics_tracking.driver')
                ->value('config_value') ?? 'seventeen_track'));

            if ($legacyDriver === '') {
                $legacyDriver = 'seventeen_track';
            }

            DB::table('merchant_logistics_tracking_settings')
                ->whereNull('driver')
                ->update(['driver' => $legacyDriver]);
        }

        $now = now();
        $availableJson = json_encode(['seventeen_track'], JSON_UNESCAPED_UNICODE);

        $legacy = DB::table('system_configs')
            ->where('config_key', 'logistics_tracking.driver')
            ->first();

        if ($legacy) {
            $value = trim((string) $legacy->config_value);
            $availableJson = json_encode(
                $value === '' ? [] : [$value],
                JSON_UNESCAPED_UNICODE
            );
        }

        DB::table('system_configs')->updateOrInsert(
            ['config_key' => 'logistics_tracking.available_drivers'],
            [
                'config_value' => $availableJson,
                'value_type' => 'json',
                'group' => 'logistics',
                'description' => '物流跟踪可选驱动枚举（JSON 数组）。空=[] 表示平台关闭；商户在物流跟踪页从中选用具体驱动。当前支持 seventeen_track=17TRACK。',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        DB::table('system_configs')->whereIn('config_key', [
            'logistics_tracking.driver',
            'logistics_tracking.seventeen_track.base_url',
        ])->delete();

        Cache::forget('system_config:logistics_tracking.available_drivers');
        Cache::forget('system_config:logistics_tracking.driver');
        Cache::forget('system_config:logistics_tracking.seventeen_track.base_url');
    }

    public function down(): void
    {
        // 不回滚已删除的系统配置键；列保留以免丢商户数据。
    }
};

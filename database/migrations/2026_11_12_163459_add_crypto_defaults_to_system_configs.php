<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            ['crypto.quote_markup_percent', '0', 'number', '应付币数量上浮百分比，0–100、最多四位小数；默认 0，不作为平台费用'],
            ['crypto.quote_ttl_seconds', '3600', 'number', '报价有效秒数（正整数），默认 3600 秒'],
            ['crypto.max_market_price_age_seconds', '300', 'number', '行情最大允许年龄秒数（正整数），默认 300 秒'],
            ['crypto.subscription_ttl_seconds', '7200', 'number', '即时订阅保留秒数（正整数），默认 7200 秒；实际期限覆盖有效报价'],
            ['crypto.subscription_ttl_overrides', '{}', 'json', '网络:资产对应订阅秒数的 JSON 对象；空对象继承全局时长'],
            ['crypto.subscription_reopen_max_renewals', '3', 'number', '原报价有效时允许恢复订阅的次数，默认 3；0 表示不自动恢复，仍须补查完成'],
            ['crypto.history_scan_interval_seconds', '300', 'number', '历史扫描健康检查最大间隔秒数（正整数），默认 300 秒；不改变每分钟扫描调度'],
        ];

        DB::transaction(function () use ($settings): void {
            $now = now();

            foreach ($settings as [$key, $value, $type, $description]) {
                DB::table('system_configs')->insertOrIgnore([
                    'config_key' => $key,
                    'config_value' => $value,
                    'value_type' => $type,
                    'group' => 'crypto',
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('system_configs')->where('config_key', $key)
                    ->where(fn (Builder $query): Builder => $query->whereNull('config_value')->orWhere('config_value', ''))
                    ->update(['config_value' => $value, 'updated_at' => $now]);
            }
        }, 3);

        foreach ($settings as [$key]) {
            Cache::forget('system_config:'.$key);
        }
    }

    /**
     * 回滚保留部署前已有配置及管理员之后修改的值，避免删除正在使用的收款参数。
     */
    public function down(): void {}
};

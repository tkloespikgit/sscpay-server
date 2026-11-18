<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use App\Support\CryptoRuntimeSettings;
use App\Support\CryptoSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CryptoSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['crypto.quote_markup_percent', '0', SystemConfig::TYPE_NUMBER, '应付币数量上浮百分比，0–100、最多四位小数；默认 0，不作为平台费用'],
            ['crypto.quote_ttl_seconds', '3600', SystemConfig::TYPE_NUMBER, '报价有效秒数（正整数），默认 3600 秒'],
            ['crypto.max_market_price_age_seconds', '300', SystemConfig::TYPE_NUMBER, '行情最大允许年龄秒数（正整数），默认 300 秒'],
            ['crypto.subscription_ttl_seconds', '7200', SystemConfig::TYPE_NUMBER, '即时订阅保留秒数（正整数），默认 7200 秒；实际期限覆盖有效报价'],
            [CryptoSettings::SUBSCRIPTION_OVERRIDES, '{}', SystemConfig::TYPE_JSON, '网络:资产对应订阅秒数的 JSON 对象；空对象继承全局时长'],
            ['crypto.subscription_reopen_max_renewals', '3', SystemConfig::TYPE_NUMBER, '原报价有效时允许恢复订阅的次数，默认 3；0 表示不自动恢复，仍须补查完成'],
            ['crypto.history_scan_interval_seconds', '300', SystemConfig::TYPE_NUMBER, '付款页重开及人工核账可接受的地址补查结果最大年龄秒数；不是定时补查间隔。退订补查固定第 3、7 天，每 3 小时派发'],
        ] as [$key, $value, $type, $description]) {
            DB::table('system_configs')->insertOrIgnore([
                'config_key' => $key, 'config_value' => $value, 'value_type' => $type, 'group' => 'crypto',
                'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Cache::forget('system_config:'.$key);
        }
        foreach (CryptoRuntimeSettings::definitions() as $name => $definition) {
            DB::table('system_configs')->insertOrIgnore([
                'config_key' => 'crypto.'.$name, 'config_value' => $definition['default'],
                'value_type' => $definition['type'], 'group' => 'crypto',
                'description' => __('crypto.settings.runtime', [], 'zh_CN')[$name],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            SystemConfig::forget('crypto.'.$name);
        }
    }
}

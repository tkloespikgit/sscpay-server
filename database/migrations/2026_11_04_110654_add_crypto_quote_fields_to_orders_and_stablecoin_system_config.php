<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 稳定币符号白名单系统配置（订单不再落 crypto_quote_* 快照）。
 * down() 仍清理旧版 up() 可能留下的 quote 列与本配置键，便于本地回滚。
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('system_configs')->insertOrIgnore([
            [
                'config_key' => 'crypto.stablecoin_symbols',
                'config_value' => json_encode(['USDT'], JSON_UNESCAPED_UNICODE),
                'value_type' => 'json',
                'group' => 'crypto',
                'description' => '稳定币符号白名单（JSON 数组）。P0 仅 USDT；命中则 USD→代币按 peg=1，不拉 Tatum 实时价。',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Cache::forget('system_config:crypto.stablecoin_symbols');
    }

    public function down(): void
    {
        $quoteColumns = array_values(array_filter(
            ['crypto_quote_rate', 'crypto_quote_at', 'crypto_quote_source'],
            fn (string $column): bool => Schema::hasColumn('orders', $column),
        ));

        if ($quoteColumns !== []) {
            Schema::table('orders', function (Blueprint $table) use ($quoteColumns): void {
                $table->dropColumn($quoteColumns);
            });
        }

        DB::table('system_configs')->where('config_key', 'crypto.stablecoin_symbols')->delete();
        Cache::forget('system_config:crypto.stablecoin_symbols');
    }
};

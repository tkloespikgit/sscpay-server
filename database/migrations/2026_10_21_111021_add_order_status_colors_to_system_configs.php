<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore([
            'config_key' => 'order.status_colors',
            'config_value' => json_encode([
                'pending' => 'gray',
                'paid' => 'info',
                'shipped' => 'warning',
                'completed' => 'success',
                'cancelled' => 'danger',
                'failed' => 'danger',
                'expired' => 'danger',
                'disputing' => 'warning',
                'dispute_review' => 'warning',
                'partially_refunded' => 'warning',
                'refunded' => 'danger',
                'chargeback' => 'danger',
            ], JSON_THROW_ON_ERROR),
            'value_type' => 'json',
            'group' => 'order',
            'description' => '全站订单状态颜色（JSON 对象）：状态代码对应 gray / primary / success / info / warning / danger',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('system_config:order.status_colors');
    }

    /**
     * 回滚保留可能已由管理员修改或在迁移前已存在的配置。
     */
    public function down(): void {}
};

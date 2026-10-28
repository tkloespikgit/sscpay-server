<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->string('gateway_order_no', 64)->default('')->after('order_no')
                ->comment('网关尝试引用，原始及历史尝试为空字符串');
            $table->dropUnique(['order_no_log_id_uniq']);
            $table->dropIndex(['order_no', 'external_log_id']);
            $table->dropColumn('order_no_log_id_uniq');
        });

        Schema::table('order_events', function (Blueprint $table) {
            $table->string('order_no_log_id_uniq', 160)->nullable()
                ->virtualAs("IF(deleted_at IS NULL, CONCAT(order_no, ':', gateway_order_no, ':', external_log_id), NULL)")
                ->comment('生成列：有效记录按本地订单、网关尝试和外部日志 ID 唯一');
            $table->unique('order_no_log_id_uniq');
            $table->index(['order_no', 'gateway_order_no', 'external_log_id'], 'order_events_gateway_log_index');
        });
    }

    public function down(): void
    {
        if (DB::table('order_events')->where('gateway_order_no', '!=', '')->exists()) {
            throw new RuntimeException('已归档切换渠道后的网关日志，禁止回滚删除尝试来源；请使用前向迁移。');
        }

        Schema::table('order_events', function (Blueprint $table) {
            $table->dropUnique(['order_no_log_id_uniq']);
            $table->dropIndex('order_events_gateway_log_index');
            $table->dropColumn(['order_no_log_id_uniq', 'gateway_order_no']);
        });

        Schema::table('order_events', function (Blueprint $table) {
            $table->string('order_no_log_id_uniq', 96)->nullable()
                ->virtualAs("IF(deleted_at IS NULL, CONCAT(order_no, ':', external_log_id), NULL)")
                ->comment('生成列：仅未删除记录参与 order_no+external_log_id 唯一性校验');
            $table->unique('order_no_log_id_uniq');
            $table->index(['order_no', 'external_log_id']);
        });
    }
};

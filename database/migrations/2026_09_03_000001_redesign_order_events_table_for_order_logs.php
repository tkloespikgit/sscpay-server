<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * order_events 原字段（event_type/event_status/event_description/external_trace_id）
 * 对应的是一个从未真实存在的"全局增量事件 feed"设想。实际对接的插件接口是
 * doc/s-system-payment-status-notify.md 第八节 POST /order-logs（按 s_order_id
 * 单笔查询插件侧日志），返回字段是 id/level/message/payment_method/wp_order_id/
 * request_data/response_data/callback_data/ip/user_agent/created_at，与原字段对不上，
 * 这里按接口实际形状重建表结构。request_payload/response_payload 两列语义不变
 * （分别对应 request_data/response_data），予以保留。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->dropUnique(['external_trace_id_uniq']);
            $table->dropColumn([
                'event_type',
                'event_status',
                'event_description',
                'external_trace_id',
                'external_trace_id_uniq',
            ]);
        });

        Schema::table('order_events', function (Blueprint $table) {
            $table->unsignedBigInteger('external_log_id')->after('order_no')->comment('插件侧日志 ID（/order-logs 响应的 id 字段，同一 order_no 下唯一）');
            $table->string('level', 20)->after('external_log_id')->comment('日志级别：INFO/WARNING/ERROR');
            $table->text('message')->after('level')->comment('插件侧已拼装好的人工可读描述');
            $table->string('payment_method', 50)->nullable()->after('message')->comment('网关代码，如 paypal_rest/stripe');
            $table->unsignedBigInteger('wp_order_id')->nullable()->after('payment_method')->comment('WordPress 侧订单 ID');
            $table->json('callback_payload')->nullable()->after('response_payload')->comment('Webhook 事件解析后的原始结构快照');
            $table->string('ip', 45)->nullable()->after('callback_payload')->comment('触发方 IP（支持 IPv6）');
            $table->text('user_agent')->nullable()->after('ip')->comment('触发方 User-Agent');
        });

        Schema::table('order_events', function (Blueprint $table) {
            // 软删除安全的复合唯一键：同一订单下 external_log_id 唯一，用于幂等写入去重。
            $table->string('order_no_log_id_uniq', 96)
                ->nullable()
                ->virtualAs("IF(deleted_at IS NULL, CONCAT(order_no, ':', external_log_id), NULL)")
                ->comment('生成列：仅未删除记录参与 order_no+external_log_id 唯一性校验');
            $table->unique('order_no_log_id_uniq');

            $table->index(['order_no', 'external_log_id']);
        });

        // 旧的"全局增量 feed"设计遗留的 system_configs 配置项，同步逻辑改为按订单
        // 逐笔查询 /order-logs 后不再需要，一并清理，避免后台配置页留着两个死配置。
        DB::table('system_configs')
            ->whereIn('config_key', ['order_event.external_api_url', 'order_event.external_api_token'])
            ->delete();
    }

    public function down(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->dropUnique(['order_no_log_id_uniq']);
            $table->dropIndex(['order_no', 'external_log_id']);
            $table->dropColumn([
                'order_no_log_id_uniq',
                'external_log_id',
                'level',
                'message',
                'payment_method',
                'wp_order_id',
                'callback_payload',
                'ip',
                'user_agent',
            ]);
        });

        Schema::table('order_events', function (Blueprint $table) {
            $table->string('event_type', 30)->nullable()->comment('事件类型枚举（已废弃）');
            $table->string('event_status', 20)->nullable()->comment('事件执行状态（已废弃）');
            $table->string('event_description', 255)->nullable()->comment('事件简要描述（已废弃）');
            $table->string('external_trace_id', 100)->nullable()->comment('外部系统追踪 ID（已废弃）');
        });

        Schema::table('order_events', function (Blueprint $table) {
            $table->string('external_trace_id_uniq', 100)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, external_trace_id, NULL)');
            $table->unique('external_trace_id_uniq');
        });
    }
};

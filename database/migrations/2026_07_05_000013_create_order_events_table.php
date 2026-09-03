<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户');
            $table->string('order_no', 32)->comment('关联订单号（冗余字段，不加外键约束以兼容外部数据）');
            $table->string('event_type', 30)->comment('事件类型枚举：payment_button_click/gateway_order_created/gateway_order_failed/callback_received/callback_params_parsed/payment_success/payment_failed/payment_refunded/payment_timeout');
            $table->string('event_status', 20)->comment('事件执行状态：success/failed/pending');
            $table->string('event_description', 255)->comment('事件简要描述（如 "用户点击支付按钮"）');
            $table->json('request_payload')->nullable()->comment('请求参数快照');
            $table->json('response_payload')->nullable()->comment('响应/回调参数快照');
            $table->string('external_trace_id', 100)->comment('外部系统追踪 ID（用于关联三方日志，唯一索引去重）');
            $table->timestamp('occurred_at')->comment('事件发生时间（以外部系统时间为准）');
            $table->timestamps();
            $table->softDeletes();

            // 软删除安全：用虚拟生成列使 external_trace_id 仅在未删除记录间强制唯一，
            // 保留"幂等写入去重"的语义，同时不阻塞软删除后外部系统重推同一 trace_id 的场景
            $table->string('external_trace_id_uniq', 100)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, external_trace_id, NULL)')
                ->comment('生成列：仅未删除事件参与 external_trace_id 唯一性校验');
            $table->unique('external_trace_id_uniq');

            $table->index(['merchant_id', 'order_no']);
            // 异常统计 widget：按商户 + 时间范围过滤
            $table->index(['merchant_id', 'occurred_at']);
            // 订单详情页：按 order_no 过滤、occurred_at 倒序展示该订单全部事件
            $table->index(['order_no', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};

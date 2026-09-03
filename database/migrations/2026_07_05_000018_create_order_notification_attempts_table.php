<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商户交易结果通知（回调 orders.notify_url）的每一次尝试记录。
 *
 * 重试策略（最多 5 次尝试，即初次 + 4 次重试）：
 *   第1次失败 → 30 秒后重试（第2次）
 *   第2次失败 → 5 分钟后重试（第3次）
 *   第3次失败 → 30 分钟后重试（第4次）
 *   第4次失败 → 1 小时后重试（第5次，最后一次）
 *   第5次仍失败 → 标记 exhausted，不再重试
 *
 * 一行 = 一次尝试，而不是一个订单一行汇总，这样后台订单详情页可以完整展示
 * 每一次尝试的时间、商户返回的 HTTP 状态码和响应内容。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('关联订单主表');
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户（冗余字段，便于查询隔离）');

            $table->string('notify_type', 30)->default('trade_result')->comment('通知类型（预留扩展，如 trade_result / refund_result）');
            $table->unsignedTinyInteger('attempt_number')->comment('第几次尝试（1~5）');
            $table->unsignedTinyInteger('max_attempts')->default(5)->comment('本次通知允许的最大尝试次数');

            $table->string('status', 20)->default('pending')->comment('尝试状态：pending/success/failed/exhausted');

            $table->string('notify_url', 500)->comment('本次尝试请求的地址（快照，冗余自 orders.notify_url）');
            $table->json('request_payload')->comment('发送给商户的通知内容快照');

            $table->unsignedSmallInteger('response_status_code')->nullable()->comment('商户端返回的 HTTP 状态码');
            $table->text('response_body')->nullable()->comment('商户端返回的原始响应内容（截断存储，见应用层限制）');
            $table->text('error_message')->nullable()->comment('请求异常信息（超时、连接失败等，无法获得响应时使用）');
            $table->unsignedInteger('duration_ms')->nullable()->comment('本次请求耗时（毫秒）');

            $table->timestamp('scheduled_at')->comment('本次尝试计划执行时间');
            $table->timestamp('attempted_at')->nullable()->comment('本次尝试实际执行时间');
            $table->timestamp('next_retry_at')->nullable()->comment('失败后下一次重试计划时间（已达最大次数则为 NULL）');

            $table->timestamps();
            $table->softDeletes();

            // 同一订单 + 通知类型下，尝试序号不可重复（软删除安全：见 orders 表注释里的生成列方案）
            $table->string('attempt_uniq_key', 120)
                ->nullable()
                ->virtualAs("IF(deleted_at IS NULL, CONCAT(order_id, ':', notify_type, ':', attempt_number), NULL)")
                ->comment('生成列：仅未删除记录参与 order_id+notify_type+attempt_number 唯一性校验');
            $table->unique('attempt_uniq_key');

            $table->index(['order_id', 'notify_type']);
            $table->index(['merchant_id', 'status']);
            // 队列调度器扫描到期待重试的记录
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notification_attempts');
    }
};

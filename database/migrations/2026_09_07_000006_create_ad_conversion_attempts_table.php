<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 广告转化通知（订单支付成功后告知 Meta/Google/TikTok 等广告平台）的每一次尝试记录，
 * 结构与 order_notification_attempts 完全对称，只是通知对象从"商户 notify_url"
 * 换成了"广告平台转化 API"，一个订单可能同时命中多个平台，因此按
 * order_id + platform 区分（而不是 order_id + notify_type）。
 *
 * 重试策略与商户通知共用同一套惯例（见 AdConversionAttempt::configuredMaxAttempts()/
 * configuredRetryIntervals()，默认最多 5 次，间隔 30秒/5分钟/30分钟/1小时）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_conversion_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('关联订单主表');
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户（冗余字段，便于查询隔离）');

            $table->string('platform', 20)->comment('广告平台：meta/google/tiktok');
            $table->unsignedTinyInteger('attempt_number')->comment('第几次尝试（1~5）');
            $table->unsignedTinyInteger('max_attempts')->default(5)->comment('本次通知允许的最大尝试次数');

            $table->string('status', 20)->default('pending')->comment('尝试状态：pending/success/failed/exhausted');

            $table->json('request_payload')->comment('发送给广告平台转化 API 的内容快照（含订单透传的 ad_params 原始片段）');

            $table->unsignedSmallInteger('response_status_code')->nullable()->comment('广告平台返回的 HTTP 状态码');
            $table->text('response_body')->nullable()->comment('广告平台返回的原始响应内容（截断存储）');
            $table->text('error_message')->nullable()->comment('请求异常信息（超时、连接失败、凭证缺失等）');
            $table->unsignedInteger('duration_ms')->nullable()->comment('本次请求耗时（毫秒）');

            $table->timestamp('scheduled_at')->comment('本次尝试计划执行时间');
            $table->timestamp('attempted_at')->nullable()->comment('本次尝试实际执行时间');
            $table->timestamp('next_retry_at')->nullable()->comment('失败后下一次重试计划时间（已达最大次数则为 NULL）');

            $table->timestamps();
            $table->softDeletes();

            // 同一订单 + 平台下，尝试序号不可重复（软删除安全，与 order_notification_attempts 同一方案）
            $table->string('attempt_uniq_key', 120)
                ->nullable()
                ->virtualAs("IF(deleted_at IS NULL, CONCAT(order_id, ':', platform, ':', attempt_number), NULL)")
                ->comment('生成列：仅未删除记录参与 order_id+platform+attempt_number 唯一性校验');
            $table->unique('attempt_uniq_key');

            $table->index(['order_id', 'platform']);
            $table->index(['merchant_id', 'status']);
            // 队列调度器扫描到期待重试的记录
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_conversion_attempts');
    }
};

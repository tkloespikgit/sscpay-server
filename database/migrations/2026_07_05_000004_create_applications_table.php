<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户');
            $table->string('name', 100)->comment('应用名称（如 "Hat Store Frontend"）');
            $table->string('website', 255)->nullable()->comment('官网域名（如 hat.com，用于校验发件域名）');
            $table->text('remark')->nullable()->comment('备注');
            $table->string('app_id', 64)->comment('API 应用 ID（公开、全局唯一）');
            $table->string('api_key', 255)->comment('API 密钥（加密存储）— 用于签名鉴权');
            $table->boolean('is_order_email_enabled')->default(true)->comment('邮件开关');
            $table->string('sender_email', 255)->nullable()->comment('发件人邮箱（如 notify@hat.com）');
            $table->string('sender_name', 255)->nullable()->comment('发件人名称（如 Hat Shop Support）');
            $table->boolean('status')->default(true)->comment('启用/禁用（禁用后 API 无法调用）');
            $table->timestamps();
            $table->softDeletes();

            $table->index('merchant_id');
            $table->index(['merchant_id', 'status']);

            // 软删除安全的唯一约束：见 orders 表注释，MySQL 唯一索引对 NULL 值不去重，
            // 用虚拟生成列使 app_id 仅在未删除记录间强制唯一
            $table->string('app_id_uniq', 64)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, app_id, NULL)')
                ->comment('生成列：仅未删除应用参与 app_id 唯一性校验');
            $table->unique('app_id_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};

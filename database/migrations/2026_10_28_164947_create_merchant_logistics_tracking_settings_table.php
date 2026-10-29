<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_logistics_tracking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()
                ->comment('所属商户（每商户一条物流跟踪凭证）');
            $table->string('driver', 40)->nullable()->comment('选用驱动（须在系统 available_drivers 枚举内）');
            $table->string('base_url', 255)->nullable()->comment('Provider API Base URL（可空，驱动默认）');
            $table->text('api_token')->nullable()->comment('Provider API Token（加密存储）');
            $table->text('webhook_secret')->nullable()->comment('Webhook 验签 secret（加密存储）');
            $table->string('webhook_token', 64)->comment('公开 Webhook 路径令牌');
            $table->timestamps();
            $table->softDeletes();

            $table->unsignedBigInteger('merchant_id_uniq')
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, merchant_id, NULL)')
                ->comment('生成列：仅未删除记录参与 merchant_id 唯一性');
            $table->unique('merchant_id_uniq');
            $table->unique('webhook_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_logistics_tracking_settings');
    }
};

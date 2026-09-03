<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户（每个商户只能绑定一个机器人）');
            $table->string('bot_token', 255)->comment('Telegram Bot Token（加密存储）');
            $table->string('chat_id', 100)->comment('接收消息的 Chat ID（用户/群组/频道 ID）');
            $table->boolean('is_enabled')->default(true)->comment('是否启用通知');
            $table->timestamp('last_sent_at')->nullable()->comment('最后发送时间');
            $table->timestamp('test_sent_at')->nullable()->comment('测试消息发送时间');
            $table->timestamps();
            $table->softDeletes();

            // 软删除安全：用虚拟生成列使 merchant_id 仅在未删除记录间强制唯一
            // （移除了 merchant_id 上原本的 inline unique()，改由生成列承担）
            $table->unsignedBigInteger('merchant_id_uniq')
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, merchant_id, NULL)')
                ->comment('生成列：仅未删除记录参与 merchant_id 唯一性校验（每商户仅一个机器人）');
            $table->unique('merchant_id_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bots');
    }
};

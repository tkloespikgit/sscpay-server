<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_bots.bot_token 使用了 encrypted cast，入库前会被 Laravel 加密，
 * 密文（含 iv/mac/tag 等的 base64 JSON）比明文（约 46 字符的 Bot Token）
 * 长得多，varchar(255) 放不下，导致保存机器人时报 "Data too long for
 * column 'bot_token'"。改为 text 类型以容纳加密后的密文。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_bots', function (Blueprint $table) {
            $table->text('bot_token')->comment('Telegram Bot Token（加密存储）')->change();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_bots', function (Blueprint $table) {
            $table->string('bot_token', 255)->comment('Telegram Bot Token（加密存储）')->change();
        });
    }
};

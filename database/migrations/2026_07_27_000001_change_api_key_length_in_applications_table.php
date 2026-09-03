<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * applications.api_key 使用了 encrypted cast，入库前会被 Laravel 加密，
 * 密文（含 iv/mac 等）比明文（KEY_ 前缀 + 32 位随机串 + 时间戳，约 47 字符）
 * 长得多，varchar(255) 放不下，导致创建应用时报 "Data too long for
 * column 'api_key'"。改为 text 类型以容纳加密后的密文。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('api_key')->comment('API 密钥（加密存储）— 用于签名鉴权')->change();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('api_key', 255)->comment('API 密钥（加密存储）— 用于签名鉴权')->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理后台表单未强制要求 replacement 必填，留空提交时是 null 而不是空字符串，
 * 撞上 NOT NULL 约束插入失败。数据库列同步放开为可选，
 * ReplaceKeyword::applyReplacements() 已用 (string) 转换兼容 null。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replace_keywords', function (Blueprint $table) {
            $table->string('replacement', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('replace_keywords', function (Blueprint $table) {
            $table->string('replacement', 255)->change();
        });
    }
};

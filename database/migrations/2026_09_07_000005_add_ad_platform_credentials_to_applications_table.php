<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 应用维度的广告平台转化 API 凭证（Meta/Google/TikTok），按平台分 key 存放，
 * 与 mail_credentials 一样使用 encrypted:array cast 加密存储（见 Application 模型）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('ad_platform_credentials')->nullable()->after('mail_credentials')->comment('广告平台转化 API 凭证，按平台（meta/google/tiktok）分 key 存放，加密存储');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('ad_platform_credentials');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API 下单时接口调用方透传的广告追踪参数（如 Meta fbclid/fbp/fbc、Google gclid、
 * TikTok ttclid 等），按平台分 key 存放，系统不解析具体字段含义，原样落库、
 * 原样转发给对应广告平台的转化 API（见 AdConversionService）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('ad_params')->nullable()->after('remark')->comment('下单时透传的广告追踪参数，按平台（meta/google/tiktok）分 key 存放');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ad_params');
        });
    }
};

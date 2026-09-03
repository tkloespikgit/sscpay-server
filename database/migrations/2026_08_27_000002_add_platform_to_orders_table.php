<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 orders 加 platform 字段，记录订单来自哪种电商网站
 * （如 wordpress / shopyy / shopline / invoice / opencart）。
 *
 * 枚举范围不写死在代码里，而是由 system_configs 的 order.platforms
 * 配置项（JSON 数组）动态维护，代码侧的兜底列表见 Order::PLATFORMS_FALLBACK。
 *
 * 字段可空：历史订单没有这个信息；手工建单也不强制填写。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('platform', 50)->nullable()->after('source')
                ->comment('电商网站平台类型（如 wordpress/shopyy/shopline/invoice/opencart），枚举范围见系统配置 order.platforms');
            // 后台订单列表按平台筛选
            $table->index(['merchant_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'platform']);
            $table->dropColumn('platform');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单对接 WordPress 支付网关插件（/pay）后，需要保存远程创建的结果：
 * - pay_url：插件渲染的收银台页面地址（即客户的实际支付链接）
 * - wp_order_id：WordPress 侧订单 ID，后续 /sync-tracking 等接口需要引用
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('pay_url', 2048)->nullable()->after('cancel_url');
            $table->unsignedBigInteger('wp_order_id')->nullable()->after('pay_url');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['pay_url', 'wp_order_id']);
        });
    }
};

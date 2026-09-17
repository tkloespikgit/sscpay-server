<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 记录下单时商户是否显式传了 payment_method_key（指定渠道，跳过支付组路由）。
 * 非空即代表这笔订单走的是「源网站直连」这条路径，供 Telegram 通知区分
 * 「订单创建」「支付成功」是否需要额外提醒（见 SendTelegramNotification）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('designated_payment_method_key', 100)->nullable()->after('payment_method_id')
                ->comment('下单时商户显式指定的支付渠道 method_code；未指定（走支付组路由）则为空');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('designated_payment_method_key');
        });
    }
};

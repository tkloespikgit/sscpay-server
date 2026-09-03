<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 三方支付通道的交易单号（如 PayPal 商户侧交易号），来自 payment_status webhook
 * 或 /order-query 的 transaction_id 字段（doc/s-system-payment-status-notify.md
 * 第二、七节）。同一笔订单在争议/拒付等后续事件里通常复用同一个交易号
 * （见文档第四节示例），所以不需要历史多值——一个字段存"最新一次拿到的值"即可，
 * 不需要唯一约束（网关侧偶发重放/测试环境可能出现同号，不应该因此拒单）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('transaction_id', 100)->nullable()->after('wp_order_id')->comment('三方支付通道交易单号（如 PayPal 交易号），来自 payment_status webhook 或 order-query');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
            $table->dropColumn('transaction_id');
        });
    }
};

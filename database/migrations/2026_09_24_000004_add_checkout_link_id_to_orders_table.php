<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 记录订单是由哪条收款链接产生的（source='checkout_link' 时非空）。
 *
 * 不用 orders.source 一个字段就够了吗？不够——source 只能告诉你"这单来自某条
 * 收款链接"，但商户通常同时挂着多条链接（不同商品、不同广告位），要回答
 * "哪条链接转化好"必须精确到链接 ID。
 *
 * restrictOnDelete：链接被删了也不能让订单失去归属，和 orders 表里
 * merchant_id / application_id / payment_group_id 的处理方式保持一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('checkout_link_id')->nullable()->after('payment_group_id')
                ->constrained()->restrictOnDelete()
                ->comment('产生该订单的收款链接；非收款链接来源的订单为空');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['checkout_link_id']);
            $table->dropColumn('checkout_link_id');
        });
    }
};

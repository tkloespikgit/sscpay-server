<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单对接 WordPress 支付网关插件（/pay）时，需要向商城系统传递发票号与订单主题：
 * - invoice_number：支付方式 invoice 前缀 + 客户名 + 客户姓 + 系统订单号，去空格并转大写
 * - subject：支付方式虚拟商品前缀 + 系统订单号
 * 两者在远程创建支付订单时随 payload 一并发送给 WordPress，同时落库到本地订单便于追溯。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_number', 255)->nullable()->after('order_no')
                ->comment('发票号：invoice 前缀_客户名_客户姓_系统订单号（去空格、转大写）');
            $table->string('subject', 255)->nullable()->after('invoice_number')
                ->comment('订单主题：虚拟商品前缀 + 系统订单号');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['invoice_number', 'subject']);
        });
    }
};

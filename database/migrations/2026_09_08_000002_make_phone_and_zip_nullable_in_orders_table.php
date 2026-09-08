<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 部分商户站点（如 WooCommerce 结账页关闭了手机号/邮编字段）下单时不会传
 * customer.phone / shipping_address.zip，API 校验放开为可选后，
 * 数据库列也要同步放开，否则依旧会因 NOT NULL 约束插入失败。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_phone', 30)->nullable()->comment('手机号')->change();
            $table->string('shipping_zip', 20)->nullable()->comment('邮政编码')->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_phone', 30)->comment('手机号')->change();
            $table->string('shipping_zip', 20)->comment('邮政编码')->change();
        });
    }
};

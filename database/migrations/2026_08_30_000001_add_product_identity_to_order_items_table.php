<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // 商品唯一标识与商品页链接：下单接口必填；历史数据允许为空。
            $table->string('product_id', 64)->nullable()->after('product_sku')->comment('商户侧商品唯一标识');
            $table->string('product_url', 500)->nullable()->after('product_id')->comment('商品详情页链接');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['product_id', 'product_url']);
        });
    }
};

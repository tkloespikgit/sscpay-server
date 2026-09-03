<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品自动匹配（OrderItemService::matchItems）的匹配总额可能超出订单
 * 商品金额，超出部分（订单原始币种）计入折扣，单独存为 matched_discount，
 * 与商户下单时传的 discount 区分，远程创建支付订单时同步给 WordPress。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('matched_discount', 15, 2)->default(0)
                ->comment('自动匹配商品溢出产生的折扣（原始币种，正数表示减免）')
                ->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('matched_discount');
        });
    }
};

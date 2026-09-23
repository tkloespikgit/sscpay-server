<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款单与余额流水的按日统计索引。
 *
 * 每日统计里"退款"取自 order_refunds、"拒付"取自 merchant_balance_transactions
 * 的 type=chargeback 流水，两者都按 created_at 做跨商户的日期范围扫描。
 * 而这两张表现有的索引全是 merchant_id 打头
 * （order_refunds: [merchant_id, order_id]；
 *   merchant_balance_transactions: [merchant_id, type]、[merchant_id, created_at]），
 * 跨商户的日期范围查询一个都用不上。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            $table->index('created_at', 'order_refunds_created_at_index');
        });

        Schema::table('merchant_balance_transactions', function (Blueprint $table) {
            // type 做等值定位（chargeback 在全部流水里占比很小），created_at 做范围扫描
            $table->index(['type', 'created_at'], 'mbt_type_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            $table->dropIndex('order_refunds_created_at_index');
        });

        Schema::table('merchant_balance_transactions', function (Blueprint $table) {
            $table->dropIndex('mbt_type_created_at_index');
        });
    }
};

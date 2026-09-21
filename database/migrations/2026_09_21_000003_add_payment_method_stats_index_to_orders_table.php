<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付路由的风控/均衡统计索引。
 *
 * PaymentService::dailyStatsForMethods() 与 monthlyAmountForMethod() 是下单主链路上
 * 的热查询（每建一单都会跑），条件是：payment_method_id IN (...) + created_at 落在
 * 当天/当月窗口 + status 不在"从未收到过钱"的集合里，再 SUM(converted_amount)。
 *
 * 原来建表时的 (merchant_id, payment_method, status, created_at) 索引已经用不上了：
 * 统计维度先是从 method_code 换成 payment_method_id（系统级支付方式被多商户共用，
 * 按 code + 商户统计会串账），又去掉了 merchant_id（限额保护的是通道本身，要跨商户
 * 汇总），索引最左列直接落空。实测退化成全索引扫描，命中率约 3%。
 *
 * 列顺序按"等值 → 范围 → 过滤"排：payment_method_id 做等值/IN 定位，created_at 做
 * 窗口范围扫描，status 留在索引里就地过滤（NOT IN 无法做范围 seek，但能避免为不匹配
 * 的行回表）。converted_amount 不放进索引：它只在最终聚合时用到，窗口内的行数有界，
 * 回表代价可控，放进去会明显撑大索引。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(
                ['payment_method_id', 'created_at', 'status'],
                'orders_payment_method_id_created_at_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_payment_method_id_created_at_status_index');
        });
    }
};

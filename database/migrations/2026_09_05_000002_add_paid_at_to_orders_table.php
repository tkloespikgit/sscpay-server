<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付成功时间。orders 原本只有 status='paid' 这个状态位，没有记录"什么时候变成
 * paid"的时间点，物流批量导入模板需要导出"支付成功时间"这一列（见
 * LogisticsImportService::TEMPLATE_HEADERS），因此补一列快照。
 *
 * 写入时机见 OrderPaymentStatusService::applyStatus()：网关 webhook / order-query
 * 首次把订单推进到"已收款状态族"（paid 及其后续流转状态）时落一次，之后不再覆盖——
 * 争议胜诉回退到 paid、退款改状态等都不应该改写这个时间。
 *
 * 历史订单该列为 NULL，导出时呈现为空，不做回填（无从考证真实支付时间）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('status')->comment('首次进入已收款状态的时间（支付成功时间），历史数据为 NULL');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};

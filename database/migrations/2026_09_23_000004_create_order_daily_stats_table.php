<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单每日统计（商户 × 应用 × 支付方式）。由 order-stats:aggregate 定时重算，
 * 供后台「订单统计」看板读取，见 App\Services\OrderStatsAggregator。
 *
 * 【归日口径：事件时间，不是下单时间】
 * 每个指标按各自"事件真正发生的时间"归日——支付按 orders.paid_at、失败按
 * orders.failed_at、退款按 order_refunds.created_at、拒付按拒付流水的 created_at。
 * 这样过去的日子一旦统计完就不再变动（三天前下的单今天退款，落进今天那格，
 * 不会回头改三天前）。若按下单时间归日就是 cohort 口径，每次重算都要把历史
 * 全部重刷，且数字会持续漂移。
 *
 * 【时区】stat_date 按系统时区 config('app.timezone') 划分，与 PaymentService
 * 的风控窗口口径一致（见该类 dayRange() 的注释：同一口径全局只能有一个，
 * 否则跨时区商户之间的窗口互不对齐）。后台展示时区（merchants.timezone）
 * 只影响时间戳怎么显示，不影响这里怎么分桶——看板上有一行说明。
 *
 * 【金额】统一 converted_amount（USD 成交额，不扣手续费），与仪表盘「总成交额」
 * 和订单列表「其中已支付」三处对齐，可直接对账。退款金额例外，取实际退款额
 * （order_refunds.amount_usd），因为支持部分退款。
 *
 * 【为什么 payment_method_id 不加外键】这是去规范化的汇总表。加外键会继承
 * orders.payment_method_id 的 nullOnDelete——支付方式被删时会把已经统计好的
 * 历史行的维度追溯改写掉。用 0 当"未知渠道"哨兵，顺带省掉可空列做唯一索引
 * 需要的生成列（MySQL 唯一索引不对 NULL 去重）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_daily_stats', function (Blueprint $table) {
            $table->id();

            $table->date('stat_date')->comment('统计日期（系统时区）');

            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户');
            $table->foreignId('application_id')->constrained()->restrictOnDelete()->comment('来源应用');
            $table->unsignedBigInteger('payment_method_id')->default(0)
                ->comment('支付方式 ID；0 表示渠道已删除或历史数据未回填。刻意不加外键，见迁移注释');

            // 支付成功：口径为"曾经支付成功过"（Order::scopePaidEver()，
            // NEVER_PAID_STATUSES 的补集），不看订单终态——订单一发货状态就变成
            // shipped，只数 status='paid' 会把已发货/已完成的成交额全漏掉。
            $table->unsignedInteger('paid_orders')->default(0)->comment('支付成功订单数');
            $table->decimal('paid_amount', 15, 2)->default(0)->comment('支付成功金额（USD）');

            // 失败：只数 status='failed'，不含 cancelled/expired（业务方指定口径）
            $table->unsignedInteger('failed_orders')->default(0)->comment('支付失败订单数');
            $table->decimal('failed_amount', 15, 2)->default(0)->comment('支付失败金额（USD）');

            // 退款：订单数按当天去重（同一单当天多次部分退款算一单），金额取实退合计
            $table->unsignedInteger('refunded_orders')->default(0)->comment('发生退款的订单数（当天去重）');
            $table->decimal('refunded_amount', 15, 2)->default(0)->comment('实际退款金额合计（USD）');

            $table->unsignedInteger('chargeback_orders')->default(0)->comment('拒付订单数');
            $table->decimal('chargeback_amount', 15, 2)->default(0)->comment('拒付金额（USD）');

            $table->timestamps();
            // 无软删除：可随时由 order-stats:aggregate 重算重建的派生数据，不是审计记录

            $table->unique(['stat_date', 'merchant_id', 'application_id', 'payment_method_id'], 'order_daily_stats_grain_unique');
            // 看板按周期取数（当天/昨天/当月/上一月），日期打头
            $table->index('stat_date');
            $table->index(['merchant_id', 'stat_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_daily_stats');
    }
};

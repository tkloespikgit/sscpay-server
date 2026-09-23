<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付失败时间快照，语义与 paid_at 完全对称（见 add_paid_at_to_orders_table）。
 *
 * 【为什么不用 created_at 当失败订单的统计时间】
 * 订单每日统计（order_daily_stats）按"事件发生时间"归日，这样过去的日子一旦统计完
 * 就不再变动。失败订单若按下单时间归日就破坏了这个前提：付款链接默认 7 天有效
 * （Order::scopeValidPaymentLink），周一下的单可能周四才被网关判为 failed，而周一
 * 那格早在周二凌晨就统计完封存了——补不回去，历史数据会持续漂移。
 *
 * 也不能用 updated_at：发货、回填 transaction_id、写 invoice_number 都会刷新它
 * （见 OrderPaymentStatusService / OrderCreationService），统计桶会每次重算都换一天。
 *
 * 写入时机见 OrderPaymentStatusService::applyStatus()，与 paid_at 一样用 empty()
 * 守卫只落首次。历史订单该列为 NULL，统计时回退 created_at（口径与
 * PaymentService::dailyStatsForMethods() 对 paid_at 的兜底写法一致）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('paid_at')
                ->comment('首次进入支付失败状态的时间，历史数据为 NULL');

            // 每日统计要跨全部商户按日期范围扫，现有索引全是 merchant_id /
            // payment_method_id 打头的，一个都用不上。
            // 列顺序按"等值 → 范围"排：status='failed' 选择性高，放最左定位，
            // failed_at 做窗口范围扫描（口径同 add_payment_method_stats_index_to_orders_table）。
            $table->index(['status', 'failed_at'], 'orders_status_failed_at_index');

            // 支付成功统计用：paid_at 做范围扫描，status 留在索引里就地过滤
            // （NOT IN 无法 seek，但能避免为不匹配的行回表）。
            $table->index(['paid_at', 'status'], 'orders_paid_at_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_failed_at_index');
            $table->dropIndex('orders_paid_at_status_index');
            $table->dropColumn('failed_at');
        });
    }
};

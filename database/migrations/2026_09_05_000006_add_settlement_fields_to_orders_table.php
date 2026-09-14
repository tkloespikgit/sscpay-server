<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 下单时按锁定的支付方式当前费率算出的手续费快照 + 实际到账金额，
            // BalanceService::creditForPaidOrder() 按 settlement_amount（而不是
            // converted_amount 全额）给商户入账。三者都可空，历史订单在下方回填。
            $table->decimal('fee_percent_amount', 15, 2)->nullable()->after('surcharge_fee')->comment('百分比手续费金额（USD）');
            $table->decimal('fee_fixed_amount', 15, 2)->nullable()->after('fee_percent_amount')->comment('固定手续费金额（USD，下单时的支付方式 fee_fixed 快照）');
            $table->decimal('settlement_amount', 15, 2)->nullable()->after('fee_fixed_amount')->comment('实际到账金额（USD）= converted_amount - fee_percent_amount - fee_fixed_amount');
        });

        // 历史订单在本功能上线前没有手续费概念，BalanceService 当时是按 converted_amount
        // 全额入账的——回填时手续费按 0 处理、settlement_amount = converted_amount，
        // 保证历史订单这三个新字段与它们已经写死的余额流水口径一致，不需要也不应该
        // 重新触发一遍入账。
        DB::table('orders')->whereNull('settlement_amount')->update([
            'fee_percent_amount' => 0,
            'fee_fixed_amount' => 0,
            'settlement_amount' => DB::raw('converted_amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fee_percent_amount', 'fee_fixed_amount', 'settlement_amount']);
        });
    }
};

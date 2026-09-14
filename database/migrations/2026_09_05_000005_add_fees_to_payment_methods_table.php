<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 交易手续费（区别于 refund_fee/chargeback_fee 那两个退款/拒付场景专用的
            // 固定手续费）：支付成功入账时按这两项算出实际到账金额，见 OrderCreationService。
            $table->decimal('fee_percent', 8, 4)->default(0)->after('chargeback_fee')->comment('交易百分比手续费（如 3.5 表示 3.5%）');
            $table->decimal('fee_fixed', 15, 2)->default(0)->after('fee_percent')->comment('交易固定手续费（USD）');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['fee_percent', 'fee_fixed']);
        });
    }
};

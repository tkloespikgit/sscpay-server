<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 累计已退款金额（订单原币种），用于部分退款封顶校验：
            // sum(refunds) <= amount，全额退完置 refunded，未退完置 partially_refunded。
            // 拒付走独立的 chargeback 状态，不累计到这里（拒付只能全额）。
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('status')->comment('累计已退款金额（订单原币种）');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });
    }
};

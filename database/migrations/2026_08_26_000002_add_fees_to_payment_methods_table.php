<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 固定金额手续费（USD）。退款时按笔收取，拒付时按次收取。
            $table->decimal('refund_fee', 15, 2)->default(0)->after('max_amount_per_month')->comment('退款手续费（固定金额，USD）');
            $table->decimal('chargeback_fee', 15, 2)->default(0)->after('refund_fee')->comment('拒付手续费（固定金额，USD）');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['refund_fee', 'chargeback_fee']);
        });
    }
};

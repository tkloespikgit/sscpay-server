<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            $table->decimal('principal_deducted_usd', 15, 2)->nullable()->comment('商户实际扣除的退款本金 USD；历史空值沿用 amount_usd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('order_refunds')->whereColumn('principal_deducted_usd', '<>', 'amount_usd')->exists()) {
            throw new RuntimeException('存在按净入账上限扣款的退款记录，回退会丢失实扣本金。');
        }

        Schema::table('order_refunds', function (Blueprint $table) {
            $table->dropColumn('principal_deducted_usd');
        });
    }
};

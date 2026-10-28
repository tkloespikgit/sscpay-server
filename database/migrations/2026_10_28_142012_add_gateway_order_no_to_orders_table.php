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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('gateway_order_no', 64)->nullable()->unique()
                ->comment('当前网关尝试的 s_order_id；空值沿用本地 order_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('gateway_order_no')->exists()) {
            throw new RuntimeException('已有订单使用独立网关订单号，不能回滚并丢失远端关联；请前向修复。');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['gateway_order_no']);
            $table->dropColumn('gateway_order_no');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 订单发货后是否将物流信息（物流公司、运单号）同步到对应站点。
            // 默认开启，保持与上线前既有行为一致。
            $table->boolean('sync_logistics')->default(true)->after('virtual_product_prefix')
                ->comment('是否同步物流信息到站点');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('sync_logistics');
        });
    }
};

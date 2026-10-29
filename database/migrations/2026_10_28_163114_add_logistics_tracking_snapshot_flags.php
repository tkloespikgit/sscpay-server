<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 应用级「物流状态跟踪查询」开关 + 订单建单快照。
 * 开关变更只影响之后新单；历史订单默认 false。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('is_logistics_tracking_enabled')->default(false)->after('is_auto_discount_enabled')
                ->comment('物流状态跟踪查询；仅影响之后新订单');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('logistics_tracking_enabled')->default(false)->after('auto_discount')
                ->comment('建单时物流跟踪开关快照');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('logistics_tracking_enabled');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('is_logistics_tracking_enabled');
        });
    }
};

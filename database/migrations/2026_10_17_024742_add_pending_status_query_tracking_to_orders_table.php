<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('pending_status_query_count')->default(0)
                ->comment('待支付订单定时补查已领取次数，最多两次，失败也计次');
            $table->timestamp('pending_status_last_queried_at')->nullable()
                ->comment('最近一次定时补查领取时间，不包含手工查询');
            $table->index(
                ['status', 'paid_at', 'pending_status_query_count', 'created_at'],
                'orders_pending_status_sync_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_pending_status_sync_index');
            $table->dropColumn(['pending_status_query_count', 'pending_status_last_queried_at']);
        });
    }
};

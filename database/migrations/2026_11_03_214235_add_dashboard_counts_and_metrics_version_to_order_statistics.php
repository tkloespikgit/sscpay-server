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
        Schema::table('order_daily_stats', function (Blueprint $table): void {
            $table->unsignedInteger('created_orders')->default(0);
            $table->unsignedInteger('unpaid_terminal_orders')->default(0);
        });

        Schema::table('order_stats_runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('metrics_version')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_stats_runs', function (Blueprint $table): void {
            $table->dropColumn('metrics_version');
        });

        Schema::table('order_daily_stats', function (Blueprint $table): void {
            $table->dropColumn(['created_orders', 'unpaid_terminal_orders']);
        });
    }
};

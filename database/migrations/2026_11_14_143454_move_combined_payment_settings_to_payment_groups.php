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
        Schema::table('payment_groups', function (Blueprint $table): void {
            $table->boolean('is_combine_payment_enabled')->default(false);
            $table->decimal('max_combined_amount', 15, 2)->nullable()->comment('组合订单最高总金额（USD）');
        });
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('is_combine_payment_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->boolean('is_combine_payment_enabled')->default(false);
        });
        Schema::table('payment_groups', function (Blueprint $table): void {
            $table->dropColumn(['is_combine_payment_enabled', 'max_combined_amount']);
        });
    }
};

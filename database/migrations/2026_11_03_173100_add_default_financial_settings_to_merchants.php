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
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('default_fee_percent', 8, 4)->default(0);
            $table->decimal('default_fee_fixed', 15, 2)->default(0);
            $table->decimal('default_refund_fee', 15, 2)->default(0);
            $table->decimal('default_chargeback_fee', 15, 2)->default(0);
            $table->decimal('default_reserve_percent', 8, 4)->default(0);
            $table->unsignedInteger('default_reserve_days')->default(0);
        });

        Schema::table('merchant_payment_settings', function (Blueprint $table) {
            $table->string('settlement_mode', 20)->nullable();
            $table->unsignedInteger('settlement_days')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_payment_settings', function (Blueprint $table) {
            $table->dropColumn(['settlement_mode', 'settlement_days']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'default_fee_percent', 'default_fee_fixed', 'default_refund_fee',
                'default_chargeback_fee', 'default_reserve_percent', 'default_reserve_days',
            ]);
        });
    }
};

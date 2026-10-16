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
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->decimal('reserve_percent', 8, 4)->default(0);
            $table->unsignedInteger('reserve_days')->default(0);
        });

        Schema::create('merchant_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('fee_percent', 8, 4)->nullable();
            $table->decimal('fee_fixed', 15, 2)->nullable();
            $table->decimal('refund_fee', 15, 2)->nullable();
            $table->decimal('chargeback_fee', 15, 2)->nullable();
            $table->decimal('reserve_percent', 8, 4)->nullable();
            $table->unsignedInteger('reserve_days')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'payment_method_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('reserve_percent', 8, 4)->default(0);
            $table->unsignedInteger('reserve_days')->default(0);
            $table->decimal('refund_fee_snapshot', 15, 2)->nullable();
            $table->decimal('chargeback_fee_snapshot', 15, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('refund_fee_snapshot')->orWhereNotNull('chargeback_fee_snapshot')->exists()) {
            throw new RuntimeException('订单财务快照已启用，禁止回滚删除快照；请使用前向迁移。');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['reserve_percent', 'reserve_days', 'refund_fee_snapshot', 'chargeback_fee_snapshot']);
        });
        Schema::dropIfExists('merchant_payment_settings');
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['reserve_percent', 'reserve_days']);
        });
    }
};

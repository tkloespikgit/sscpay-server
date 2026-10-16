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
        Schema::create('order_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('remaining_amount', 15, 2);
            $table->decimal('reserve_amount', 15, 2)->default(0);
            $table->decimal('available_amount', 15, 2)->default(0);
            $table->string('mode', 20);
            $table->unsignedInteger('cycle_days');
            $table->decimal('reserve_percent', 8, 4)->default(0);
            $table->unsignedInteger('reserve_days')->default(0);
            $table->string('status', 30);
            $table->timestamp('started_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
            $table->index(['merchant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('order_settlements')->exists()) {
            throw new RuntimeException('订单结算审计记录已产生，禁止回滚删除；请使用前向迁移。');
        }

        Schema::dropIfExists('order_settlements');
    }
};

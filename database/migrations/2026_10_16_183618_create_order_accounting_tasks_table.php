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
        Schema::create('order_accounting_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->comment('有意不设外键，避免状态事务持订单锁时反向锁商户；执行前核对归属');
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'type']);
            $table->index(['status', 'id']);
            $table->index(['merchant_id', 'order_id']);
            $table->index(['merchant_id', 'status', 'type', 'order_id'], 'accounting_merchant_status_type_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('order_accounting_tasks')->exists()) {
            throw new RuntimeException('订单记账审计记录已产生，禁止回滚删除；请使用前向迁移。');
        }

        Schema::dropIfExists('order_accounting_tasks');
    }
};

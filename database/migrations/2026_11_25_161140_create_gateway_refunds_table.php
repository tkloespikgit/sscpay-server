<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->char('identity_hash', 64)->unique();
            $table->char('order_refund_hash', 64)->unique();
            $table->string('gateway_reference');
            $table->string('payment_method', 32);
            $table->boolean('livemode');
            $table->string('refund_id');
            $table->string('payment_reference');
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->dateTime('refunded_at');
            $table->string('status', 16)->default('pending');
            $table->string('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->foreignId('order_refund_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['merchant_id', 'status']);
        });

        Schema::create('gateway_refund_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gateway_refund_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->char('fingerprint', 64)->unique();
            $table->json('facts');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('gateway_refunds')->exists()) {
            throw new RuntimeException('已保存渠道退款凭证，请使用前向迁移以保留扣款幂等和财务审计记录。');
        }

        Schema::dropIfExists('gateway_refund_conflicts');
        Schema::dropIfExists('gateway_refunds');
    }
};

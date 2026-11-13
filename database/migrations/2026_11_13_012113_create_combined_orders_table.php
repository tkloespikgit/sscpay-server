<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combined_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_no', 32)->unique();
            $table->string('merchant_order_no', 64);
            $table->string('source', 20);
            $table->string('platform', 50)->nullable();
            $table->string('currency', 3);
            $table->string('converted_currency', 3)->default('USD');
            foreach (['original_amount', 'auto_discount', 'amount', 'converted_amount', 'paid_amount', 'paid_converted_amount', 'remaining_amount', 'remaining_converted_amount'] as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }
            $table->decimal('exchange_rate', 15, 6);
            $table->json('exchange_snapshot');
            $table->json('order_snapshot');
            $table->string('status', 24)->default('pending');
            $table->json('current_quote')->nullable();
            $table->foreignId('current_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('next_sequence')->default(1);
            $table->unsignedInteger('version')->default(0);
            $table->unsignedTinyInteger('webhook_version')->default(2);
            $table->string('payment_link_token', 64)->unique();
            $table->string('checkout_host', 255);
            $table->string('checkout_origin', 500);
            foreach (['notify_url', 'return_url', 'cancel_url'] as $column) {
                $table->string($column, 500)->nullable();
            }
            $table->boolean('send_mail')->default(false);
            $table->timestamp('payment_link_sent_at')->nullable();
            $table->string('payment_link_mail_failed_reason')->nullable();
            $table->timestamp('first_paid_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('merchant_order_no_uniq', 64)->nullable()
                ->virtualAs('IF(deleted_at IS NULL, merchant_order_no, NULL)');
            $table->unique(['merchant_id', 'merchant_order_no_uniq'], 'combined_orders_merchant_number_unique');
            $table->index(['merchant_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combined_orders');
    }
};

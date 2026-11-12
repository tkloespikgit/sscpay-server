<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_address_monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_payment_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_address_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->uuid('callback_uuid')->unique();
            $table->string('callback_url', 500);
            $table->string('network', 48);
            $table->string('environment', 16);
            $table->char('credential_fingerprint', 64);
            $table->string('subscription_id', 128)->nullable()->unique();
            $table->string('status', 24)->default('preparing');
            $table->unsignedInteger('ttl_seconds');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 48)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('backfill_to_block')->nullable();
            $table->string('backfill_to_hash', 128)->nullable();
            $table->timestamp('backfill_completed_at')->nullable();
            $table->unsignedBigInteger('cold_scan_to_block')->nullable();
            $table->string('cold_scan_to_hash', 128)->nullable();
            $table->timestamp('cold_scan_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['crypto_payment_request_id', 'generation'], 'crypto_monitor_request_generation_unique');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'expires_at']);
        });
        Schema::table('crypto_payment_requests', function (Blueprint $table): void {
            $table->foreignId('current_monitor_id')->nullable()->constrained('crypto_address_monitors')->restrictOnDelete();
        });
        Schema::create('crypto_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_address_monitor_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_payment_request_id')->constrained()->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->string('transaction_hash', 64);
            $table->json('hint_payload');
            $table->string('signature_mode', 24);
            $table->string('status', 24)->default('pending');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['crypto_address_monitor_id', 'payload_hash'], 'crypto_receipt_monitor_payload_unique');
            $table->index(['status', 'id']);
            $table->index(['merchant_id', 'crypto_payment_request_id'], 'crypto_receipt_merchant_request_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_webhook_receipts');
        Schema::table('crypto_payment_requests', function (Blueprint $table): void {
            $table->dropForeign(['current_monitor_id']);
            $table->dropColumn('current_monitor_id');
        });
        Schema::dropIfExists('crypto_address_monitors');
    }
};

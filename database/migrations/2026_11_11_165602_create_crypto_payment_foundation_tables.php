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
        Schema::create('crypto_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('network', 48);
            $table->string('address', 128);
            $table->char('address_key', 64)->unique();
            $table->char('source_fingerprint', 64);
            $table->unsignedInteger('derivation_index');
            $table->unsignedBigInteger('scan_from_block');
            $table->timestamps();
            $table->unique(['source_fingerprint', 'derivation_index'], 'crypto_address_source_index_unique');
            $table->index(['merchant_id', 'order_id']);
        });

        Schema::create('crypto_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_address_id')->unique()->constrained()->restrictOnDelete();
            $table->string('network', 48);
            $table->string('asset', 16);
            $table->unsignedTinyInteger('decimals');
            $table->string('status', 24)->default('preparing');
            $table->string('due_units', 78);
            $table->string('fee_units', 78);
            $table->string('net_units', 78);
            $table->json('quote_snapshot');
            $table->timestamp('expires_at');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'order_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('crypto_chain_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_payment_request_id')->constrained()->restrictOnDelete();
            $table->string('network', 48);
            $table->string('asset', 16);
            $table->string('transaction_hash', 128);
            $table->string('event_position', 128);
            $table->char('event_key', 64)->unique();
            $table->string('amount_units', 78);
            $table->string('block_hash', 128);
            $table->unsignedBigInteger('block_height');
            $table->timestamp('block_time');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'crypto_payment_request_id'], 'crypto_event_merchant_request_index');
        });

        Schema::create('crypto_asset_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('network', 48);
            $table->string('asset', 16);
            $table->string('balance_units', 78)->default('0');
            $table->string('reserved_units', 78)->default('0');
            $table->timestamps();
            $table->unique(['merchant_id', 'network', 'asset'], 'crypto_balance_merchant_asset_unique');
        });

        Schema::create('crypto_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('crypto_payment_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_asset_balance_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->string('type', 32);
            $table->string('network', 48);
            $table->string('asset', 16);
            $table->string('gross_units', 78);
            $table->string('fee_units', 78);
            $table->string('amount_units', 78);
            $table->string('balance_before_units', 78);
            $table->string('balance_after_units', 78);
            $table->json('fee_snapshot');
            $table->timestamps();
            $table->index(['merchant_id', 'network', 'asset', 'created_at'], 'crypto_ledger_merchant_asset_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_ledger_entries');
        Schema::dropIfExists('crypto_asset_balances');
        Schema::dropIfExists('crypto_chain_events');
        Schema::dropIfExists('crypto_payment_requests');
        Schema::dropIfExists('crypto_addresses');
    }
};

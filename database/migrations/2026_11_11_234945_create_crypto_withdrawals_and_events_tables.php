<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_asset_balance_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('network', 48);
            $table->string('asset', 16);
            $table->unsignedTinyInteger('decimals');
            $table->string('amount_units', 78);
            $table->string('fee_limit_units', 78);
            $table->string('reserved_units', 78);
            $table->string('actual_fee_units', 78)->nullable();
            $table->string('destination_address', 128);
            $table->string('status', 24)->default('pending');
            $table->string('remark', 500)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_remark', 500)->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('manual_reference', 128)->nullable();
            $table->string('completion_remark', 500)->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'request_key'], 'crypto_withdrawal_request_unique');
            $table->index(['merchant_id', 'status', 'id'], 'crypto_withdrawal_merchant_status_index');
            $table->index(['status', 'id']);
        });

        Schema::create('crypto_withdrawal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_withdrawal_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_asset_balance_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('balance_before_units', 78);
            $table->string('balance_after_units', 78);
            $table->string('reserved_before_units', 78);
            $table->string('reserved_after_units', 78);
            $table->string('reason', 500)->nullable();
            $table->string('manual_reference', 128)->nullable();
            $table->timestamps();
            $table->unique(['crypto_withdrawal_id', 'action'], 'crypto_withdrawal_action_unique');
            $table->index(['merchant_id', 'crypto_asset_balance_id', 'id'], 'crypto_withdrawal_event_balance_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_withdrawal_events');
        Schema::dropIfExists('crypto_withdrawals');
    }
};

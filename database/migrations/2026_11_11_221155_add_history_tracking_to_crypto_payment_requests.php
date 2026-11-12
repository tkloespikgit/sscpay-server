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
        Schema::table('crypto_payment_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('history_to_block')->nullable();
            $table->string('history_to_hash', 128)->nullable();
            $table->timestamp('history_to_time')->nullable();
            $table->json('history_recent_blocks')->nullable();
            $table->timestamp('history_checked_at')->nullable();
            $table->timestamp('history_next_attempt_at')->nullable();
            $table->string('history_error_code', 48)->nullable();
            $table->string('review_reason', 48)->nullable();
            $table->timestamp('settlement_next_attempt_at')->nullable();
            $table->index(['network', 'history_next_attempt_at'], 'crypto_request_history_due_index');
            $table->index(['status', 'settlement_next_attempt_at'], 'crypto_request_settlement_due_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crypto_payment_requests', function (Blueprint $table): void {
            $table->dropIndex('crypto_request_history_due_index');
            $table->dropIndex('crypto_request_settlement_due_index');
            $table->dropColumn(['history_to_block', 'history_to_hash', 'history_to_time', 'history_recent_blocks',
                'history_checked_at', 'history_next_attempt_at', 'history_error_code', 'review_reason', 'settlement_next_attempt_at']);
        });
    }
};

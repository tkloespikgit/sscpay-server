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
        Schema::table('crypto_webhook_receipts', function (Blueprint $table): void {
            $table->unsignedInteger('verification_attempts')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->index(['status', 'next_attempt_at'], 'crypto_receipt_verification_due_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crypto_webhook_receipts', function (Blueprint $table): void {
            $table->dropIndex('crypto_receipt_verification_due_index');
            $table->dropColumn(['verification_attempts', 'last_checked_at', 'next_attempt_at', 'last_error_code']);
        });
    }
};

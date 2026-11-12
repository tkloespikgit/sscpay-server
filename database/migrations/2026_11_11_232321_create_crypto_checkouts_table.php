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
        Schema::create('crypto_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_request_id')->nullable()->constrained('crypto_payment_requests')->restrictOnDelete();
            $table->json('fee_snapshot');
            $table->string('status', 24)->default('checking');
            $table->string('action', 16)->default('open');
            $table->string('phase', 16)->default('backfill');
            $table->timestamp('requested_at');
            $table->timestamp('handoff_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('error_code', 48)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::table('crypto_payment_requests', function (Blueprint $table) {
            $table->foreignId('replaces_request_id')->nullable()->unique()->constrained('crypto_payment_requests')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crypto_payment_requests', function (Blueprint $table) {
            $table->dropForeign(['replaces_request_id']);
            $table->dropUnique(['replaces_request_id']);
            $table->dropColumn('replaces_request_id');
        });
        Schema::dropIfExists('crypto_checkouts');
    }
};

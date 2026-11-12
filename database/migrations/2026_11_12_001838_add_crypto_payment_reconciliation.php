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
        Schema::table('crypto_asset_balances', function (Blueprint $table): void {
            $table->string('debt_units', 78)->default('0');
        });
        foreach (['crypto_ledger_entries', 'crypto_withdrawal_events'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('debt_before_units', 78)->default('0');
                $table->string('debt_after_units', 78)->default('0');
            });
        }
        Schema::create('crypto_payment_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_payment_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_asset_balance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('network', 48);
            $table->string('asset', 16);
            $table->string('action', 24);
            $table->char('evidence_hash', 64);
            $table->json('evidence_snapshot');
            $table->json('fee_snapshot');
            foreach (['gross', 'fee', 'net', 'balance', 'debt'] as $field) {
                $table->string($field.'_before_units', 78);
                $table->string($field.'_after_units', 78);
            }
            $table->string('reserved_units', 78);
            $table->string('reason', 500);
            $table->timestamps();
            $table->unique(['merchant_id', 'request_key'], 'crypto_resolution_key_unique');
            $table->index(['order_id', 'id']);
            $table->index(['merchant_id', 'network', 'asset', 'created_at'], 'crypto_resolution_stats_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_payment_resolutions');
        foreach (['crypto_ledger_entries', 'crypto_withdrawal_events'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['debt_before_units', 'debt_after_units']));
        }
        Schema::table('crypto_asset_balances', fn (Blueprint $table) => $table->dropColumn('debt_units'));
    }
};

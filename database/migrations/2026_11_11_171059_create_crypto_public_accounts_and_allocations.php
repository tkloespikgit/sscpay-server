<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_public_accounts', function (Blueprint $table): void {
            $table->id();
            $table->char('fingerprint', 64)->unique();
            $table->string('family', 16);
            $table->string('extended_public_key', 111);
            $table->string('derivation_profile', 64);
            $table->unsignedBigInteger('next_index')->default(2);
            $table->timestamps();
        });
        Schema::create('crypto_address_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('crypto_public_account_id')->constrained()->restrictOnDelete();
            $table->string('allocation_key', 64)->unique();
            $table->unsignedInteger('derivation_index');
            $table->string('network', 48);
            $table->string('asset', 16);
            $table->string('status', 24)->default('reserved');
            $table->string('address', 128)->nullable();
            $table->char('address_key', 64)->nullable()->unique();
            $table->json('public_config_snapshot');
            $table->timestamp('recovery_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['crypto_public_account_id', 'derivation_index'], 'crypto_allocation_source_index_unique');
            $table->index(['merchant_id', 'order_id']);
        });
        Schema::table('crypto_addresses', function (Blueprint $table): void {
            $table->foreignId('crypto_address_allocation_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crypto_addresses', function (Blueprint $table): void {
            $table->dropForeign(['crypto_address_allocation_id']);
            $table->dropUnique(['crypto_address_allocation_id']);
            $table->dropColumn('crypto_address_allocation_id');
        });
        Schema::dropIfExists('crypto_address_allocations');
        Schema::dropIfExists('crypto_public_accounts');
    }
};

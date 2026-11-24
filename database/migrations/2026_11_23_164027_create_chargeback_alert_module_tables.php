<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chargeback_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('provider', 32);
            $table->string('external_id', 120);
            $table->string('environment', 16);
            $table->string('timezone', 64);
            $table->text('signing_config');
            $table->json('services');
            $table->boolean('enabled')->default(false);
            $table->json('sync_cursor')->nullable();
            $table->dateTime('next_sync_at')->nullable();
            $table->dateTime('last_sync_at')->nullable();
            $table->string('sync_error', 64)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id'], 'chargeback_provider_identity_unique');
            $table->index(['enabled', 'next_sync_at']);
        });

        Schema::create('chargeback_channel_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chargeback_provider_account_id')->constrained(indexName: 'chargeback_binding_account_fk')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('service', 32);
            $table->string('scope_key', 255);
            $table->char('scope_hash', 64);
            $table->string('reference_type', 24)->default('arn');
            $table->dateTime('valid_from');
            $table->dateTime('valid_until')->nullable();
            $table->timestamps();
            $table->index(['chargeback_provider_account_id', 'service', 'scope_hash'], 'chargeback_binding_scope_index');
        });

        Schema::create('chargeback_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chargeback_provider_account_id')->constrained(indexName: 'chargeback_setting_account_fk')->restrictOnDelete();
            $table->string('service', 32);
            $table->string('external_id', 120);
            $table->string('scope_key', 255)->nullable();
            $table->string('remote_status', 32)->nullable();
            $table->json('details')->nullable();
            $table->dateTime('observed_at');
            $table->unsignedBigInteger('observed_ms');
            $table->timestamps();
            $table->unique(['chargeback_provider_account_id', 'service', 'external_id'], 'chargeback_setting_identity_unique');
        });

        Schema::create('chargeback_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chargeback_provider_account_id')->constrained(indexName: 'chargeback_alert_account_fk')->restrictOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('service', 32);
            $table->string('external_id', 120);
            $table->string('scope_key', 255)->nullable();
            $table->string('descriptor', 255)->nullable();
            $table->boolean('scope_is_explicit')->default(false);
            $table->string('arn', 100)->nullable();
            $table->string('provider_transaction_id', 100)->nullable();
            $table->string('provider_order_reference', 120)->nullable();
            $table->string('alert_type', 32)->nullable();
            $table->string('reason_code', 50)->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('trade_at')->nullable();
            $table->decimal('trade_amount', 15, 2)->nullable();
            $table->char('trade_currency', 3)->nullable();
            $table->decimal('case_amount', 15, 2)->nullable();
            $table->char('case_currency', 3)->nullable();
            $table->string('remote_status', 64)->nullable();
            $table->string('charge_level', 32)->nullable();
            $table->unsignedBigInteger('notice_observed_ms')->nullable();
            $table->string('match_status', 24)->default('pending');
            $table->boolean('evidence_conflict')->default(false);
            $table->dateTime('next_match_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('feedback_due_at')->nullable();
            $table->dateTime('remote_feedback_at')->nullable();
            $table->json('remote_feedback')->nullable();
            $table->dateTime('next_notice_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('deadline_notified_at')->nullable();
            $table->unsignedTinyInteger('notice_attempts')->default(0);
            $table->timestamps();
            $table->unique(['chargeback_provider_account_id', 'service', 'external_id'], 'chargeback_alert_identity_unique');
            $table->index(['merchant_id', 'id']);
            $table->index(['next_match_at', 'id']);
            $table->index(['next_notice_at', 'id']);
        });

        Schema::create('chargeback_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chargeback_alert_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('source', 16);
            $table->char('fingerprint', 64)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['chargeback_alert_id', 'fingerprint'], 'chargeback_alert_event_unique');
        });

        Schema::create('chargeback_alert_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chargeback_alert_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->json('payload');
            $table->string('evidence_reference', 120)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('order_chargeback_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('gateway_reference', 64);
            $table->string('arn', 100);
            $table->char('arn_hash', 64);
            $table->timestamps();
            $table->unique(['order_id', 'gateway_reference', 'arn_hash'], 'order_chargeback_reference_unique');
            $table->index('arn_hash');
        });

        foreach (['chargeback_alerts.view', 'chargeback_alerts.process'] as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['chargeback_alerts', 'chargeback_provider_accounts', 'chargeback_provider_settings', 'order_chargeback_references'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('拒付预警配置或审计已产生，请使用前向迁移。');
            }
        }

        Schema::dropIfExists('order_chargeback_references');
        Schema::dropIfExists('chargeback_alert_feedback');
        Schema::dropIfExists('chargeback_alert_events');
        Schema::dropIfExists('chargeback_alerts');
        Schema::dropIfExists('chargeback_provider_settings');
        Schema::dropIfExists('chargeback_channel_bindings');
        Schema::dropIfExists('chargeback_provider_accounts');
    }
};

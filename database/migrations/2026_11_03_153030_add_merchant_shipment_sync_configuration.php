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
        Schema::table('applications', function (Blueprint $table): void {
            $table->boolean('is_shipment_sync_enabled')->default(false)->comment('回传物流至商户网站');
            $table->string('shipment_sync_url', 2048)->nullable()->comment('完整 HTTPS 物流回传地址，保留 query');
        });

        Schema::create('order_shipment_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_shipping_id')->constrained()->restrictOnDelete();
            $table->json('payload')->comment('本次物流业务快照，不含签名或密钥');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('alert_status', 20)->nullable();
            $table->timestamp('next_alert_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at'], 'shipment_sync_due_index');
            $table->index(['alert_status', 'next_alert_at'], 'shipment_sync_alert_index');
            $table->index(['order_id', 'id'], 'shipment_sync_order_index');
        });

        Schema::create('order_shipment_sync_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_shipment_sync_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->text('request_url');
            $table->json('request_headers')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
            $table->unique(['order_shipment_sync_id', 'attempt_number'], 'shipment_sync_attempt_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_shipment_sync_attempts');
        Schema::dropIfExists('order_shipment_syncs');
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['is_shipment_sync_enabled', 'shipment_sync_url']);
        });
    }
};

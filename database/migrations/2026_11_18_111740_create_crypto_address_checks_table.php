<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('crypto_address_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('check_key', 64);
            $table->string('kind', 16);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('due_at');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'check_key']);
            $table->index(['status', 'due_at', 'next_attempt_at'], 'crypto_address_checks_due_index');
            $table->index(['merchant_id', 'order_id', 'id']);
        });
        Schema::table('crypto_payment_requests', function (Blueprint $table) {
            $table->json('history_query_state')->nullable();
        });
        Schema::table('crypto_webhook_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('crypto_address_monitor_id')->nullable()->change();
        });
        DB::table('system_configs')->where('config_key', 'crypto.history_scan_interval_seconds')
            ->where('description', '历史扫描健康检查最大间隔秒数（正整数），默认 300 秒；不改变每分钟扫描调度')
            ->update(['description' => '付款页重开及人工核账可接受的地址补查结果最大年龄秒数；不是定时补查间隔。退订补查固定第 3、7 天，每 3 小时派发']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('crypto_webhook_receipts')->whereNull('crypto_address_monitor_id')->exists()
            || DB::table('crypto_address_checks')->exists()) {
            throw new RuntimeException('Address checks contain audit records. Keep this schema and deploy a forward correction instead of deleting audit history.');
        }
        Schema::table('crypto_webhook_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('crypto_address_monitor_id')->nullable(false)->change();
        });
        Schema::dropIfExists('crypto_address_checks');
        Schema::table('crypto_payment_requests', function (Blueprint $table) {
            $table->dropColumn('history_query_state');
        });
    }
};

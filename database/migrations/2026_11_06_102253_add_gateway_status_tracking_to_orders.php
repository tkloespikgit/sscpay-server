<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('normal_status', 20)->nullable();
            $table->string('gateway_status', 20)->nullable();
            $table->dateTime('gateway_status_updated_at')->nullable();
            $table->unsignedBigInteger('gateway_status_version')->default(0);
        });

        Schema::create('order_gateway_status_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->comment('不加外键，避免持订单锁时反向锁商户');
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('gateway_reference', 64);
            $table->string('source', 10);
            $table->string('status', 20);
            $table->dateTime('gateway_updated_at')->nullable();
            $table->string('outcome', 30);
            $table->json('payload');
            $table->timestamps();
            $table->index(['merchant_id', 'order_id', 'id']);
            $table->index(['outcome', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('order_gateway_status_events')->exists()) {
            throw new RuntimeException('网关状态审计记录已产生，禁止回滚删除；请使用前向迁移。');
        }

        Schema::dropIfExists('order_gateway_status_events');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['normal_status', 'gateway_status', 'gateway_status_updated_at', 'gateway_status_version']);
        });
    }
};

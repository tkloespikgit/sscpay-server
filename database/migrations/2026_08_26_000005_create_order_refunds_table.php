<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户（冗余，便于按商户过滤）');
            $table->foreignId('order_id')->constrained()->restrictOnDelete()->comment('关联订单');

            // 退款金额按订单原币种输入，封顶校验用它累计；amount_usd 为记账口径。
            $table->string('currency', 3)->comment('退款币种（= 订单原币种）');
            $table->decimal('amount', 15, 2)->comment('退款金额（订单原币种）');
            $table->decimal('exchange_rate', 15, 6)->comment('换算汇率快照（取自订单）');
            $table->decimal('amount_usd', 15, 2)->comment('退款金额换算 USD（记账口径）');
            $table->decimal('fee', 15, 2)->default(0)->comment('退款手续费快照（USD，取自支付方式）');

            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete()->comment('操作管理员');
            $table->text('reason')->nullable()->comment('退款理由');

            $table->timestamps();

            $table->index(['merchant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
    }
};

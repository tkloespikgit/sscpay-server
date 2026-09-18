<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 系统级支付方式（payment_methods.merchant_id 为空）分配给多个商户使用的中间表，
 * 写法对齐 observer_payment_methods。只用于"系统级支付方式 → 可使用它的商户"，
 * 商户自建的支付方式不经过这张表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('可使用该系统级支付方式的商户');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete()->comment('被分配的系统级支付方式');
            $table->timestamps();

            $table->unique(['merchant_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payment_methods');
    }
};

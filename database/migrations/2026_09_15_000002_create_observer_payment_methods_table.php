<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observer_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observer_id')->constrained('observers')->cascadeOnDelete()->comment('关联观察者账户');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete()->comment('关联支付方式');
            $table->timestamps();

            $table->unique(['observer_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observer_payment_methods');
    }
};

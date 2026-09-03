<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3)->default('USD')->comment('基准币种（默认 USD）');
            $table->string('target_currency', 3)->comment('目标币种');
            $table->decimal('rate', 15, 6)->comment('汇率值（1 目标币种 = ? 基准币种）');
            $table->timestamp('retrieved_at')->useCurrent()->comment('该汇率数据的获取/生效时间');
            $table->timestamps();

            $table->unique(['base_currency', 'target_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};

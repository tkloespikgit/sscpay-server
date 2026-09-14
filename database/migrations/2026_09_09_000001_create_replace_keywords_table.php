<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replace_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // 关键词替换：COPY 模式下把订单商品名里的 keyword 替换成 replacement（忽略大小写）。
            $table->string('keyword', 255);
            $table->string('replacement', 255);
            $table->timestamps();
            $table->softDeletes();
            $table->index('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replace_keywords');
    }
};

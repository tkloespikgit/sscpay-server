<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 排序权重不再在支付方式编辑表单里暴露（原来是个 0-100 的滑块），
 * 新建记录统一走数据库默认值。默认值从 0 调到 100，和支付组内
 * 优先级（pivot priority 默认 100）保持同一语义：数值越小越靠前，
 * 需要提前的再手动改小。只改默认值，不动存量数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->integer('sort_order')->default(100)->comment('排序权重（数值越小越靠前）')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->comment('排序权重（数值越小越靠前）')->change();
        });
    }
};

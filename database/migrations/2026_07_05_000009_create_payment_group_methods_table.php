<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_group_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('payment_groups')->cascadeOnDelete()->comment('关联支付组');
            $table->foreignId('method_id')->constrained('payment_methods')->cascadeOnDelete()->comment('关联支付方式');
            $table->integer('priority')->default(100)->comment('组内优先级（数值越小越优先推荐）');
            $table->timestamps();

            $table->unique(['group_id', 'method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_group_methods');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('is_auto_discount_enabled')->default(false)->comment('新订单自动随机折扣');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('original_amount', 15, 2)->nullable()->comment('自动折扣前请求应付金额，用于幂等校验；历史订单为空');
            $table->decimal('auto_discount', 15, 2)->default(0)->comment('应用自动折扣快照，原币种，已计入 discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['original_amount', 'auto_discount']);
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('is_auto_discount_enabled');
        });
    }
};

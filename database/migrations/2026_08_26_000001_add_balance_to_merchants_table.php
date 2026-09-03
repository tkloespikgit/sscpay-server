<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // 记账统一 USD。balance 为"总权益"（可用 + 冻结），frozen_balance 为
            // 提现审核中被冻结的部分；对商户展示的"可提现"= balance - frozen_balance。
            $table->decimal('balance', 15, 2)->default(0)->after('status')->comment('账户总余额（USD）');
            $table->decimal('frozen_balance', 15, 2)->default(0)->after('balance')->comment('冻结余额（提现审核中，USD）');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['balance', 'frozen_balance']);
        });
    }
};

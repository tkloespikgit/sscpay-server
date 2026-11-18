<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('logistics_import_task_records', function (Blueprint $table) {
            $table->text('merchant_order_no')->nullable()->after('order_no')
                ->comment('文件里填写的商户订单号原文，长度校验由逐行同步执行');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_import_task_records', function (Blueprint $table) {
            $table->dropColumn('merchant_order_no');
        });
    }
};

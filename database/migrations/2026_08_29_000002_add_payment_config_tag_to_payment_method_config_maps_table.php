<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付类型配置模板增加支付类型标签（payment_config_tag）。
 * 表单层面必填；数据库保持 nullable 是为了兼容已有的模板数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_method_config_maps', function (Blueprint $table) {
            $table->string('payment_config_tag', 100)->nullable()->after('name')
                ->comment('支付类型标签，表单层面必填');
        });
    }

    public function down(): void
    {
        Schema::table('payment_method_config_maps', function (Blueprint $table) {
            $table->dropColumn('payment_config_tag');
        });
    }
};

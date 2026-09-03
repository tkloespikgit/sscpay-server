<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 网关对接账号凭证。按需求密码类字段也明文存储、不做加密。
            $table->string('order_account', 255)->nullable()->after('domain_client_sk')
                ->comment('创建订单账户');
            $table->string('order_password', 255)->nullable()->after('order_account')
                ->comment('创建订单密码（明文存储）');
            $table->string('config_account', 255)->nullable()->after('order_password')
                ->comment('创建配置账户');
            $table->string('config_password', 255)->nullable()->after('config_account')
                ->comment('创建配置密码（明文存储）');
            $table->string('payment_config_id', 255)->nullable()->after('config_password')
                ->comment('支付配置 ID');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['order_account', 'order_password', 'config_account', 'config_password', 'payment_config_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 是否允许商城系统订单支付完成后返回源站（对应插件 allow_returned_source 字段 Y/N）。
            // 订单 platform=invoice 时无视此配置强制不允许，见 OrderCreationService::createRemotePayment()。
            $table->boolean('allow_returned_source')->default(true)->after('sync_logistics')
                ->comment('是否允许返回源站');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('allow_returned_source');
        });
    }
};

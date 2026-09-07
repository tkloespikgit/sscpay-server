<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('order_no_prefix', 10)->nullable()->after('virtual_product_prefix')
                ->comment('系统订单号前缀，留空则不加前缀');
            $table->string('order_no_format', 10)->nullable()->after('order_no_prefix')
                ->comment('系统订单号随机部分格式：numeric=纯数字，alnum=大写字母+数字混合；为空则使用系统默认格式');
            $table->unsignedTinyInteger('order_no_length')->nullable()->after('order_no_format')
                ->comment('系统订单号总长度（含前缀），15-30；order_no_format 为空时忽略');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['order_no_prefix', 'order_no_format', 'order_no_length']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 商品匹配模式：MATCH 匹配 / CREATE 创建 / VIRTUAL 虚拟 / DIRECT 直连。
            // 允许的取值枚举由系统配置 payment.product_match_modes（JSON 数组）维护，
            // 代码侧兜底列表见 PaymentMethod::PRODUCT_MATCH_MODES_FALLBACK。
            $table->string('product_match_mode', 20)->default('MATCH')->after('domain_client_sk')
                ->comment('商品匹配模式：MATCH/CREATE/VIRTUAL/DIRECT');
            $table->string('invoice_prefix', 50)->nullable()->after('product_match_mode')
                ->comment('invoice 商品前缀');
            $table->string('virtual_product_prefix', 50)->nullable()->after('invoice_prefix')
                ->comment('虚拟商品前缀');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['product_match_mode', 'invoice_prefix', 'virtual_product_prefix']);
        });
    }
};

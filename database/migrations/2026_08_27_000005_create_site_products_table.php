<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户');
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete()->comment('来源支付方式（站点配置）');
            $table->unsignedBigInteger('woo_product_id')->comment('WooCommerce 商品 ID');
            $table->string('product_type', 30)->default('simple')->comment('商品类型：simple / variable / grouped / external');
            $table->string('name', 500)->comment('商品名称');
            $table->string('name_translated', 500)->nullable()->comment('商品名称中文翻译（百度翻译）');
            $table->string('sku', 255)->nullable()->comment('商品 SKU');
            $table->decimal('price_min', 15, 2)->default(0)->comment('销售价格下限');
            $table->decimal('price_max', 15, 2)->default(0)->comment('销售价格上限');
            $table->json('variations')->nullable()->comment('变体商品：所有变体的 ID / SKU / 价格 JSON');
            $table->string('image_url', 1024)->nullable()->comment('商品主图地址');
            $table->string('permalink', 1024)->nullable()->comment('商品详情页链接');
            $table->timestamp('synced_at')->nullable()->comment('最近同步时间');
            $table->timestamps();

            // 同一支付方式（站点）下，WooCommerce 商品 ID 唯一。
            $table->unique(['payment_method_id', 'woo_product_id']);
            $table->index(['merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_products');
    }
};

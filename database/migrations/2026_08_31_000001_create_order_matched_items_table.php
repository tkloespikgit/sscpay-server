<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 表结构与 order_items 保持一致：下单时未传商品明细的订单，
        // 由系统从支付方式绑定的站点商品中自动匹配出的明细单独存放在这里。
        Schema::create('order_matched_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('关联订单主表');
            $table->string('product_sku', 64)->nullable()->comment('商户侧商品 SKU/编号');
            $table->string('product_id', 64)->nullable()->comment('商户侧商品唯一标识');
            $table->string('product_url', 500)->nullable()->comment('商品详情页链接');
            $table->string('product_name', 255)->comment('商品名称');
            $table->text('product_description')->nullable()->comment('商品描述/规格');
            $table->decimal('unit_price', 15, 2)->comment('商品单价（原始币种）');
            $table->integer('quantity')->comment('购买数量');
            $table->decimal('total_price', 15, 2)->comment('行小计（单价 × 数量）');
            $table->decimal('converted_unit_price', 15, 2)->nullable()->comment('单价（USD）');
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_matched_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收款链接上配置的商品明细。两个用途：
 *   1. 落地页上以小票形式展示给客户看；
 *   2. 建单时作为 order_items 传给 OrderCreationService。
 *
 * ⚠️ 注意用途 2 的边界：支付方式若是 MATCH 模式，同步给电商站点的是
 * OrderItemService 随机凑出来的 order_matched_items，和这里配的商品不是
 * 同一批——这里的商品只进本地 order_items 和落地页展示。CREATE / COPY
 * 模式才会拿这些商品去站点建同价商品。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_link_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_link_id')->constrained()->cascadeOnDelete()->comment('所属收款链接');

            $table->string('product_name', 255)->comment('商品名称');
            $table->string('image_path', 500)->nullable()->comment('商品图片存储路径（OSS），落地页展示用');
            $table->decimal('unit_price', 15, 2)->comment('单价（币种同链接 currency）');
            $table->unsignedInteger('quantity')->default(1)->comment('数量');

            // 下面两个字段是 OrderCreationService 建 order_items 时要用的。
            // 收款链接场景下商户未必有真实的站点商品，留空时由 CheckoutLinkOrderService
            // 用链接自身的落地页地址与合成 ID 兜底。
            $table->string('product_sku', 64)->nullable()->comment('商户侧商品编号，可空');
            $table->string('product_url', 500)->nullable()->comment('商品详情页链接，可空（空则建单时用落地页地址兜底）');

            $table->unsignedInteger('sort_order')->default(0)->comment('展示排序，越小越靠前');
            $table->timestamps();

            $table->index(['checkout_link_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_link_items');
    }
};

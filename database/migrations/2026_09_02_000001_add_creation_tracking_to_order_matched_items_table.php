<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CREATE 匹配模式扩展字段：记录匹配/复制的源头变体，
        // 以及该行商品是否在站点上被自动创建出来。
        Schema::table('order_matched_items', function (Blueprint $table) {
            $table->unsignedBigInteger('source_variation_id')->nullable()->after('converted_unit_price')
                ->comment('来源站点商品变体（site_product_variations.id），CREATE 模式下为复制模板');
            $table->boolean('auto_created')->default(false)->after('source_variation_id')
                ->comment('CREATE 模式下该商品是否在站点上自动创建');

            $table->index('source_variation_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_matched_items', function (Blueprint $table) {
            $table->dropIndex(['source_variation_id']);
            $table->dropColumn(['source_variation_id', 'auto_created']);
        });
    }
};

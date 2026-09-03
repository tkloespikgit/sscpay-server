<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_product_id')->constrained('site_products')->cascadeOnDelete()->comment('所属站点商品');
            $table->unsignedBigInteger('woo_variation_id')->comment('WooCommerce 变体 ID');
            $table->string('sku', 255)->nullable()->comment('变体 SKU');
            $table->decimal('price', 15, 2)->default(0)->comment('变体售价');
            $table->string('currency', 10)->default('USD')->comment('金额币种（当前站点统一为美金）');
            $table->timestamps();

            // 同一商品下，WooCommerce 变体 ID 唯一。
            $table->unique(['site_product_id', 'woo_variation_id']);
        });

        // site_products 增加币种字段（当前同步的站点金额全部为美金）。
        Schema::table('site_products', function (Blueprint $table) {
            $table->string('currency', 10)->default('USD')->comment('金额币种（当前站点统一为美金）')->after('price_max');
        });

        // 把存量 variations JSON 拆到独立表，再删除 JSON 列。
        DB::table('site_products')
            ->whereNotNull('variations')
            ->orderBy('id')
            ->eachById(function ($product) {
                $variations = json_decode((string) $product->variations, true);

                if (! is_array($variations)) {
                    return;
                }

                $now = now();

                foreach ($variations as $variation) {
                    if (! is_array($variation) || ! isset($variation['id'])) {
                        continue;
                    }

                    DB::table('site_product_variations')->insert([
                        'site_product_id' => $product->id,
                        'woo_variation_id' => (int) $variation['id'],
                        'sku' => $variation['sku'] ?? null,
                        'price' => (float) ($variation['price'] ?? 0),
                        'currency' => 'USD',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

        Schema::table('site_products', function (Blueprint $table) {
            $table->dropColumn('variations');
        });
    }

    public function down(): void
    {
        Schema::table('site_products', function (Blueprint $table) {
            $table->json('variations')->nullable()->comment('变体商品：所有变体的 ID / SKU / 价格 JSON')->after('price_max');
        });

        // 还原：把独立表数据重新聚合回 JSON 列。
        DB::table('site_product_variations')
            ->orderBy('site_product_id')
            ->orderBy('id')
            ->get()
            ->groupBy('site_product_id')
            ->each(function ($variations, $siteProductId) {
                DB::table('site_products')->where('id', $siteProductId)->update([
                    'variations' => json_encode($variations->map(fn ($v) => [
                        'id' => $v->woo_variation_id,
                        'sku' => $v->sku,
                        'price' => (float) $v->price,
                    ])->values()),
                ]);
            });

        Schema::table('site_products', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::dropIfExists('site_product_variations');
    }
};

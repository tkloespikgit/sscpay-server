<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 站点商品变体：从 WooCommerce 同步下来的变体按行独立存储
 * （不再以 JSON 数组挂在商品上），金额币种当前统一为美金。
 */
class SiteProductVariation extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_product_id',
        'woo_variation_id',
        'sku',
        'price',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function siteProduct(): BelongsTo
    {
        return $this->belongsTo(SiteProduct::class);
    }
}

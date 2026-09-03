<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 站点商品：通过支付方式上配置的 WordPress 站点（domain + WooCommerce
 * REST API 密钥）同步下来的商品快照，用于本地留档与后续业务使用。
 * 变体单独存放在 site_product_variations 表，金额币种当前统一为美金。
 */
class SiteProduct extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'payment_method_id',
        'woo_product_id',
        'product_type',
        'name',
        'name_translated',
        'sku',
        'price_min',
        'price_max',
        'currency',
        'image_url',
        'permalink',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'synced_at' => 'datetime',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(SiteProductVariation::class);
    }
}

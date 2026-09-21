<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * 站点商品挂在支付方式名下，而支付方式可以是系统级（merchant_id 为 NULL、
     * 挂在管理员名下、分配给多个商户使用），这类商品的 merchant_id 同样是 NULL。
     * BelongsToMerchant 默认的 MerchantScope 严格按 merchant_id 过滤，商户级管理员
     * 在后台看自己系统级支付方式的商品统计（数量/价格区间/最近同步时间，见
     * PaymentMethodResource 的详情面板）时会全部被过滤掉、显示成 0。
     *
     * 这里用同一个 Scope 标识重新注册一份闭包实现覆盖掉 trait 那份（覆盖机制
     * 同 PaymentMethod::booted()），规则改成"跟随所属支付方式的可见范围"：
     * whereHas('paymentMethod') 会自动带上 PaymentMethod 自己的全局 Scope，
     * 即支付方式看得到，它的商品就看得到，不必再重复一遍那套三条件判断。
     * 超管不受限；API / 队列等无登录用户场景照旧不介入。
     */
    protected static function booted(): void
    {
        static::addGlobalScope(MerchantScope::class, function (Builder $builder) {
            if (! auth()->check() || ! (auth()->user() instanceof User)) {
                return;
            }

            if (auth()->user()->manageableMerchantIds() === null) {
                return;
            }

            $builder->whereHas('paymentMethod');
        });
    }

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

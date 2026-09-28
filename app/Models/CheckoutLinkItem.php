<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * 收款链接上配置的商品。落地页展示 + 建单时作为 order_items 传下去。
 *
 * 没有 merchant_id 字段，也就没有 BelongsToMerchant——商户归属完全跟随
 * 父级 checkout_link，查询一律经由 CheckoutLink 的关系走，父级已经被
 * MerchantScope 过滤过了。
 */
class CheckoutLinkItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_link_id',
        'product_name',
        'image_path',
        'unit_price',
        'quantity',
        'product_sku',
        'product_url',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function checkoutLink(): BelongsTo
    {
        return $this->belongsTo(CheckoutLink::class);
    }

    /**
     * 行小计。用 bcmul 而不是浮点乘法——金额一律走 BCMath，
     * 和 OrderCreationService / OrderItem::sumTotalPrice() 保持同一套算法，
     * 否则小计会在第二位小数上和服务端校验对不上。
     */
    public function totalPrice(): string
    {
        return bcmul((string) $this->unit_price, (string) $this->quantity, 2);
    }

    /**
     * 商品图的公开访问地址。收款链接的图片必须是**公开可读**的——落地页跑在
     * 商户自有域名上、客户未登录，用不了争议附件那套 temporaryUrl 签名地址。
     */
    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        return Storage::disk(config('checkout.media_disk', 'public'))->url($this->image_path);
    }
}

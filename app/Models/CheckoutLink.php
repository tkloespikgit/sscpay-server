<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 收款链接。商户建一条链接 → 客户打开落地页填信息 → 当场建单 → 跳支付网关。
 *
 * 与 orders.payment_link_token 的区别见 create_checkout_links_table 迁移注释，
 * 两者不可混用。
 */
class CheckoutLink extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    /** 金额写死，客户不能改。配了商品/运费/税费时强制用这个模式。 */
    public const MODE_FIXED = 'fixed';

    /** 客户在 [min_amount, max_amount] 区间内自行输入金额。 */
    public const MODE_RANGE = 'range';

    public const AMOUNT_MODES = [self::MODE_FIXED, self::MODE_RANGE];

    /**
     * 收款链接产生的订单在 orders.source 上的取值。既有值是 api / manual，
     * 这里新增第三种，用于对账与统计时区分来源。
     */
    public const ORDER_SOURCE = 'checkout_link';

    /** 收款链接产生的订单按发票平台处理；来源仍由 ORDER_SOURCE 区分。 */
    public const ORDER_PLATFORM = Order::PLATFORM_INVOICE;

    protected $fillable = [
        'merchant_id',
        'application_id',
        'payment_group_id',
        'merchant_domain_id',
        'slug',
        'title',
        'customer_notice',
        'logo_path',
        'google_maps_browser_key',
        'supported_countries',
        'currency',
        'amount_mode',
        'fixed_amount',
        'min_amount',
        'max_amount',
        'shipping_fee',
        'tax',
        'discount',
        'is_active',
        'orders_count',
    ];

    protected function casts(): array
    {
        return [
            'fixed_amount' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'tax' => 'decimal:2',
            'discount' => 'decimal:2',
            'is_active' => 'boolean',
            'supported_countries' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $link) {
            if (blank($link->slug)) {
                $link->slug = static::generateSlug();
            }
        });
    }

    /**
     * 24 位随机串。长度不是随便定的：落地页没有任何鉴权，访问控制完全依赖
     * slug 猜不出来（和 Order::payment_link_token 同一个思路），短了就能被枚举。
     */
    public static function generateSlug(): string
    {
        return Str::random(24);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function paymentGroup(): BelongsTo
    {
        return $this->belongsTo(PaymentGroup::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(MerchantDomain::class, 'merchant_domain_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CheckoutLinkItem::class)->orderBy('sort_order');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isFixedAmount(): bool
    {
        return $this->amount_mode === self::MODE_FIXED;
    }

    /** Empty means that the merchant has not restricted countries. */
    public function availableCountries(): array
    {
        $countries = \App\Support\Countries::withPhoneCodes();

        if (empty($this->supported_countries)) {
            return $countries;
        }

        return array_intersect_key($countries, array_flip($this->supported_countries));
    }

    /**
     * 是否配置了商品明细或运费/税费。为真时金额必须是固定的——客户自己输金额
     * 的话，"商品小计 + 运费 + 税费"和实付金额就对不上了，
     * OrderCreationService 的金额公式校验会直接拒单。
     */
    public function hasItemisedAmounts(): bool
    {
        return $this->items()->exists()
            || bccomp((string) $this->shipping_fee, '0', 2) !== 0
            || bccomp((string) $this->tax, '0', 2) !== 0
            || bccomp((string) $this->discount, '0', 2) !== 0;
    }

    /**
     * 落地页完整地址。绑了自有域名就用商户域名，否则回退到平台默认域名
     * （APP_URL），保证没绑域名的商户也能先把功能用起来。
     */
    public function url(): string
    {
        $path = '/c/'.$this->slug;

        if ($this->domain && $this->domain->isReady()) {
            return 'https://'.$this->domain->host.$path;
        }

        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * Logo 的公开访问地址。和商品图一样必须是公开可读的盘，
     * 落地页的访问者是未登录的终端客户，用不了签名地址。
     */
    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        return Storage::disk(config('checkout.media_disk', 'public'))->url($this->logo_path);
    }

    /**
     * 允许承载这条链接落地页的 Host 白名单（小写，不含端口）。
     *
     * 为什么要限制而不是任意域名都放行：落地页按 Host 路由，如果不校验，
     * 任何一个已接入的商户域名都能拉起别家商户的链接页面——客户看到的是
     * A 商户的域名，付的却是 B 商户的单，是典型的钓鱼场景。
     *
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $hosts = [];

        if ($this->domain && $this->domain->isReady()) {
            $hosts[] = $this->domain->host;
        }

        // 平台默认域名始终允许：商户还没绑好自己的域名时，链接也要能先用起来。
        $platformHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (filled($platformHost)) {
            $hosts[] = strtolower($platformHost);
        }

        return array_values(array_unique($hosts));
    }

    /**
     * 本次下单的应付金额。fixed 模式忽略客户输入，range 模式用客户输入的值
     * （区间校验在 FormRequest 里做，这里只负责取值）。
     */
    public function resolveAmount(?string $customerAmount): string
    {
        if ($this->isFixedAmount()) {
            return bcadd((string) $this->fixed_amount, '0', 2);
        }

        return bcadd((string) $customerAmount, '0', 2);
    }

    /**
     * 商品小计 = Σ(unit_price × quantity)。没配商品时返回 '0.00'，
     * 由 CheckoutLinkOrderService 用"合成一条明细"的方式兜底。
     */
    public function itemsSubtotal(): string
    {
        return $this->items->reduce(
            fn (string $carry, CheckoutLinkItem $item) => bcadd($carry, $item->totalPrice(), 2),
            '0.00'
        );
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

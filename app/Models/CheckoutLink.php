<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Collection;
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
 *
 * title 与 link_name 都是必填，但用途完全不同，别混用：
 *   - title     = 对客标题，渲染在落地页顶部（checkout/layout.blade.php）与浏览器标签页；
 *   - link_name = 商户内部标识，仅后台列表识别与搜索用，**不展示给客户**。
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
        'payment_group_ids',
        'merchant_domain_id',
        'slug',
        'title',
        'link_name',
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
        'auto_redirect',
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
            'auto_redirect' => 'boolean',
            'supported_countries' => 'array',
            'payment_group_ids' => 'array',
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
        $countries = Countries::withPhoneCodes();

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

    /**
     * 落地页上可供客户选择的支付组（需求决策 Q1-A）。
     *
     * 候选 = 「可选择支付组」∪「默认支付组」，只保留**启用中**、且仍属于本链接
     * 商户的组，按 id 升序。
     *
     * 为什么要自动并入默认组：配了选择区之后，默认组依然是"这条链接的组"之一。
     * 若因为运营没在「可选择支付组」里重复勾它，就让它整块从客户眼前消失，后台
     * 那个必填字段就退化成了隐形配置——运营以为它在生效，实际客户永远看不到，
     * 只能靠一次线上投诉才发现。
     *
     * 之所以每次都重新查库，而不是直接信 JSON 里的 ID 列表：payment_group_ids 是
     * 后台保存时的快照，之后组可能被停用、被软删除、甚至被改挂到别的商户名下。
     * 直接信任快照会把失效的组渲染给客户，客户点进去还会在下单时炸掉。
     *
     * 返回空集合表示这条链接没配选择区 —— 落地页不渲染选择区，直接走默认组
     * payment_group_id（存量链接零迁移零回归）。
     *
     * @return Collection<int, PaymentGroup>
     */
    public function availablePaymentGroups(): Collection
    {
        $ids = array_map('intval', (array) ($this->payment_group_ids ?? []));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));

        // 没配可选组 = 不展示选择区。这一条必须最先判断：默认组不能把空列表
        // "撑"成非空，否则存量链接会凭空空降出一个只有一个选项的选择区。
        if ($ids === []) {
            return new Collection;
        }

        if ((int) $this->payment_group_id > 0) {
            $ids[] = (int) $this->payment_group_id;
        }

        return PaymentGroup::query()
            ->forMerchant((int) $this->merchant_id)
            ->whereIn('id', array_values(array_unique($ids)))
            // 默认组若已被停用，同样不出现在候选里——标准与可选组完全一致。
            // 渲染一个点进去必然失败的选项，比不渲染更糟。
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * 本次下单实际使用的支付组（需求决策 Q2-A：客户必选）。
     *
     * - 配了可选组：客户传上来的 ID 必须落在 availablePaymentGroups() 里（已启用 +
     *   属于本商户，**且该集合已自动包含默认组**）；非法或没传时**回退到默认组**
     *   而不是抛异常 —— 落地页的异常会被控制器统一吞成一句"下单失败"，回退至少
     *   能让客户把单走完；正常路径上，FormRequest 的 required + in 规则已经在
     *   更前面拦住了非法值，所以这条回退实际是给服务层直接调用兜的底。
     * - 没配可选组：直接用默认组。
     *
     * ⚠️ 只解析到「组」为止，绝不继续解析成具体支付方式 —— 组内通道必须交给
     * PaymentService::resolvePaymentMethod() 的加权算法 + 风控，这是需求的硬约束。
     */
    public function resolvePaymentGroup(?int $selectedId): PaymentGroup
    {
        $available = $this->availablePaymentGroups();

        if ($available->isNotEmpty() && $selectedId !== null) {
            $selected = $available->firstWhere('id', $selectedId);

            if ($selected) {
                return $selected;
            }
        }

        return $this->paymentGroup;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

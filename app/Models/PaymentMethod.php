<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    /**
     * merchant_id 为 NULL 表示"系统级"支付方式（挂在管理员名下），可通过
     * assignedMerchants() 中间表分配给多个商户使用，见下方 booted()。
     */
    public function isSystemLevel(): bool
    {
        return is_null($this->merchant_id);
    }

    /**
     * BelongsToMerchant 默认的 MerchantScope 只会严格按 merchant_id 过滤，看不到
     * "被分配的系统级支付方式"。这里用同一个 Scope 标识（MerchantScope::class）重新
     * 注册一份闭包实现，覆盖掉 trait 在 bootBelongsToMerchant() 里注册的那份——
     * Eloquent 全局 Scope 按标识存放在按 Model 类分组的数组里，同标识后注册的会
     * 覆盖先注册的，且只影响 PaymentMethod 自己，不影响其它同样用了这个 trait 的
     * Model（Order、PaymentGroup 等）。booted() 保证在 boot()（会跑完 bootTraits()）
     * 之后执行，顺序上一定能覆盖成功。
     *
     * 覆盖后的规则：非平台超管时，只能看到"merchant_id 属于自己可管理范围"、
     * "被分配（assignedMerchants）给自己可管理范围内某个商户"，或者"自己创建的
     * 系统级支付方式（owner_id 是自己）"这三类之一的记录。最后一条是必须的：
     * 商户级管理员刚创建一条系统级支付方式、还没来得及分配给任何商户时，
     * merchant_id 是 NULL、assignedMerchants 也是空，前两个条件都不成立，
     * 如果不认 owner_id，创建人保存后立刻看不到自己刚建的记录，编辑页
     * 路由绑定 firstOrFail() 直接 404（曾经真实踩过）。
     */
    protected static function booted(): void
    {
        static::addGlobalScope(MerchantScope::class, function (Builder $builder) {
            if (! auth()->check() || ! (auth()->user() instanceof User)) {
                return;
            }

            $viewer = auth()->user();
            $merchantIds = $viewer->manageableMerchantIds();

            if ($merchantIds === null) {
                return;
            }

            $builder->where(function (Builder $query) use ($merchantIds, $viewer) {
                $query->whereIn('payment_methods.merchant_id', $merchantIds)
                    ->orWhereHas('assignedMerchants', fn (Builder $q) => $q->whereIn('merchants.id', $merchantIds))
                    ->orWhere('payment_methods.owner_id', $viewer->id);
            });
        });

        static::creating(function (self $model) {
            if ($model->isSystemLevel() && empty($model->owner_id) && auth()->check()) {
                $model->owner_id = auth()->id();
            }
        });
    }

    /**
     * 覆盖 BelongsToMerchant::scopeForMerchant()：API/队列等无登录用户场景下，
     * 除了该商户自有的支付方式，还要能匹配到分配给它的系统级支付方式，
     * 否则被分配的商户在下单时指定 method_code 会解析不到（见
     * OrderCreationService::resolveDesignatedPaymentMethod()）。
     */
    public function scopeForMerchant(Builder $query, int $merchantId): Builder
    {
        return $query->withoutGlobalScope(MerchantScope::class)
            ->where(function (Builder $q) use ($merchantId) {
                $q->where('payment_methods.merchant_id', $merchantId)
                    ->orWhereHas('assignedMerchants', fn (Builder $q2) => $q2->where('merchants.id', $merchantId));
            });
    }

    /**
     * 商品匹配模式兜底列表；允许取值以系统配置 payment.product_match_modes（JSON 数组）为准。
     * DIRECT（直连）已从枚举移除：回跳地址与站点同域名时由下单流程自动走直连分支。
     */
    public const PRODUCT_MATCH_MODES_FALLBACK = ['MATCH', 'CREATE', 'VIRTUAL', 'COPY'];

    /**
     * 已实现的匹配模式：MATCH 存量商品凑单；CREATE 同价匹配 + 复制改价建站创建；
     * VIRTUAL 等同 MATCH；COPY 关键词替换商品名后按名称+同价匹配，找不到才复制改价创建。
     */
    public const MODE_MATCH = 'MATCH';

    public const MODE_CREATE = 'CREATE';

    public const MODE_VIRTUAL = 'VIRTUAL';

    public const MODE_COPY = 'COPY';

    protected $fillable = [
        'merchant_id',
        'method_code',
        'method_name',
        'is_active',
        'sort_order',
        'config_map_id',
        'config',
        'domain',
        'domain_client_id',
        'domain_client_sk',
        // order_account / order_password / config_account / config_password 已弃用：
        // 支付插件的所有接口统一改用上面的 WooCommerce REST API 密钥（Consumer Key / Secret）
        // 做 Basic Auth。数据库列暂时保留，但不再参与批量赋值与任何认证流程。
        'payment_config_id',
        'product_match_mode',
        'invoice_prefix',
        'virtual_product_prefix',
        'order_no_prefix',
        'order_no_format',
        'order_no_length',
        'sync_logistics',
        'allow_returned_source',
        'max_amount_per_transaction',
        'max_amount_per_day',
        'max_count_per_day',
        'max_amount_per_month',
        'refund_fee',
        'chargeback_fee',
        'fee_percent',
        'fee_fixed',
        'sender_email',
        'sender_name',
        'mail_driver',
        'mail_credentials',
        'payment_link_mail_template',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sync_logistics' => 'boolean',
            'allow_returned_source' => 'boolean',
            'config' => 'array',
            'max_amount_per_transaction' => 'decimal:2',
            'max_amount_per_day' => 'decimal:2',
            'max_count_per_day' => 'integer',
            'order_no_length' => 'integer',
            'max_amount_per_month' => 'decimal:2',
            'refund_fee' => 'decimal:2',
            'chargeback_fee' => 'decimal:2',
            'fee_percent' => 'decimal:4',
            'fee_fixed' => 'decimal:2',
            // 支付方式自有 ESP 凭证，与 Application::mail_credentials 是各自独立的
            // 两份配置（见 Order::resolveMailSender()），同样用 encrypted:array 存储。
            'mail_credentials' => 'encrypted:array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * 系统级支付方式的创建人（超管/商户级管理员），见 booted() 里的自动回填。
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * 系统级支付方式分配给哪些商户使用，见 database/migrations/..._create_merchant_payment_methods_table.php。
     */
    public function assignedMerchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class, 'merchant_payment_methods')->withTimestamps();
    }

    public function configMap(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodConfigMap::class, 'config_map_id');
    }

    public function paymentGroups(): BelongsToMany
    {
        return $this->belongsToMany(PaymentGroup::class, 'payment_group_methods', 'method_id', 'group_id')
            ->withPivot('priority')
            ->withTimestamps();
    }

    /**
     * 通过本站点配置（domain + WooCommerce REST API 密钥）同步下来的商品快照。
     */
    public function siteProducts(): HasMany
    {
        return $this->hasMany(SiteProduct::class);
    }

    /**
     * 信息附表：库存地址、公司/法人资料、供货商资料，只有超级管理员能维护
     * （见 App\Filament\Support\PaymentMethodProfileAction）。
     */
    public function profile(): HasOne
    {
        return $this->hasOne(PaymentMethodProfile::class);
    }

    /**
     * 允许的商品匹配模式枚举，来自系统配置 payment.product_match_modes，
     * 配置缺失时回退到 PRODUCT_MATCH_MODES_FALLBACK。
     */
    public static function supportedProductMatchModes(): array
    {
        return SystemConfig::getArray('payment.product_match_modes', self::PRODUCT_MATCH_MODES_FALLBACK);
    }

    /**
     * 0 表示该阈值不限制。
     */
    public function isUnlimited(string $field): bool
    {
        return (float) $this->{$field} === 0.0;
    }

    /**
     * 单笔金额是否超过阈值（不涉及累计统计，累计部分由 PaymentService 结合
     * Redis 计数器 / DB 查询完成，因为那部分是"当前状态"而不是配置本身）。
     */
    public function exceedsPerTransactionLimit(float $amountUsd): bool
    {
        return ! $this->isUnlimited('max_amount_per_transaction')
            && $amountUsd > (float) $this->max_amount_per_transaction;
    }

    /**
     * 该支付方式扣完百分比+固定手续费后到账金额仍 >= 0 所需的最小订单金额（USD）。
     * fee_percent 存的是百分比数值（如 3.5 表示 3.5%），公式：fee_fixed / (1 - fee_percent/100)。
     * 百分比费率 >= 100% 时无解（怎么收都会倒贴），返回 null。
     */
    public function minTransactionAmount(): ?string
    {
        $remainingRatio = bcsub('1', bcdiv((string) $this->fee_percent, '100', 6), 6);

        if (bccomp($remainingRatio, '0', 6) <= 0) {
            return null;
        }

        return bcdiv((string) $this->fee_fixed, $remainingRatio, 2);
    }
}

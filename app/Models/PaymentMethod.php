<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    /**
     * 商品匹配模式兜底列表；允许取值以系统配置 payment.product_match_modes（JSON 数组）为准。
     * DIRECT（直连）已从枚举移除：回跳地址与站点同域名时由下单流程自动走直连分支。
     */
    public const PRODUCT_MATCH_MODES_FALLBACK = ['MATCH', 'CREATE', 'VIRTUAL'];

    /** 已实现的匹配模式：MATCH 存量商品凑单；CREATE 同价匹配 + 复制改价建站创建；VIRTUAL 等同 MATCH。 */
    public const MODE_MATCH = 'MATCH';

    public const MODE_CREATE = 'CREATE';

    public const MODE_VIRTUAL = 'VIRTUAL';

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
        'order_account',
        'order_password',
        'config_account',
        'config_password',
        'payment_config_id',
        'product_match_mode',
        'invoice_prefix',
        'virtual_product_prefix',
        'sync_logistics',
        'allow_returned_source',
        'max_amount_per_transaction',
        'max_amount_per_day',
        'max_count_per_day',
        'max_amount_per_month',
        'refund_fee',
        'chargeback_fee',
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
            'max_amount_per_month' => 'decimal:2',
            'refund_fee' => 'decimal:2',
            'chargeback_fee' => 'decimal:2',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
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
}

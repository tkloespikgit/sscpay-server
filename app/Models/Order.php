<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class Order extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    /**
     * 金额公式允许的最大误差（超过即拒单，见 isAmountValid()）。
     */
    public const AMOUNT_TOLERANCE = 0.01;

    /**
     * 电商网站平台类型的兜底列表。正式枚举范围由系统配置 order.platforms
     * （JSON 数组，后台"系统配置"维护）动态决定，仅在配置缺失时退回这里。
     */
    public const PLATFORMS_FALLBACK = ['wordpress', 'shopyy', 'shopline', 'invoice', 'opencart'];

    /**
     * platform=invoice（手工发票类订单）强制不允许返回源站，
     * 见 OrderCreationService::createRemotePayment() 的 allow_returned_source 判定。
     */
    public const PLATFORM_INVOICE = 'invoice';

    /**
     * 人工发起的争议审核事件占用的订单状态（见 OrderDisputeService）。
     * 与网关 webhook 驱动的 'disputing' 是两套完全独立的机制，互不复用——
     * 详见 OrderPaymentStatusService::shouldApply() 里对这个状态的专门守卫。
     * status 列本身没有枚举 cast（其余状态值仍按历史习惯用字面量字符串），
     * 这里单独定义常量只是因为这个值需要被好几处新代码引用，避免手滑打错。
     */
    public const STATUS_DISPUTE_REVIEW = 'dispute_review';

    protected $fillable = [
        'merchant_id',
        'application_id',
        'payment_group_id',
        'order_no',
        'invoice_number',
        'subject',
        'merchant_order_no',
        'source',
        'platform',
        'payment_link_token',
        'payment_link_sent_at',
        'currency',
        'subtotal',
        'shipping_fee',
        'discount',
        'matched_discount',
        'tax',
        'amount',
        'converted_currency',
        'converted_amount',
        'subtotal_converted',
        'shipping_fee_converted',
        'discount_converted',
        'tax_converted',
        'exchange_rate',
        'original_exchange_rate',
        'surcharge_percent',
        'surcharge_type',
        'surcharge_amount',
        'surcharge_fee',
        'customer_first_name',
        'customer_last_name',
        'customer_email',
        'customer_phone',
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_city',
        'shipping_state',
        'shipping_country',
        'shipping_zip',
        'payment_method',
        'payment_method_id',
        'refunded_amount',
        'customer_ip',
        'user_agent',
        'accept_language',
        'notify_url',
        'return_url',
        'cancel_url',
        'pay_url',
        'wp_order_id',
        'transaction_id',
        'status',
        'paid_at',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'payment_link_sent_at' => 'datetime',
            'wp_order_id' => 'integer',
            'subtotal' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'matched_discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'converted_amount' => 'decimal:2',
            'subtotal_converted' => 'decimal:2',
            'shipping_fee_converted' => 'decimal:2',
            'discount_converted' => 'decimal:2',
            'tax_converted' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'original_exchange_rate' => 'decimal:6',
            'surcharge_percent' => 'decimal:4',
            'surcharge_amount' => 'decimal:6',
            'surcharge_fee' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // 兜底：如果调用方绕开了 createWithGeneratedIdentifiers() 直接 Order::create()，
        // 仍然保证 order_no / payment_link_token 不为空（两者在迁移里都是必填字段）。
        static::creating(function (self $order) {
            if (empty($order->order_no)) {
                $order->order_no = static::generateOrderNo();
            }

            if (empty($order->payment_link_token)) {
                $order->payment_link_token = static::generatePaymentLinkToken();
            }
        });
    }

    // ------------------------------------------------------------------
    // 关联关系
    // ------------------------------------------------------------------

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

    /**
     * 下单时锁定的支付方式。withTrashed()：支付方式可能后来被软删除/停用，
     * 历史订单仍应展示当时锁定的 method_name，不能因为关联被软删除就丢显示。
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * 自动匹配的商品明细：下单未传 items 时，系统从支付方式站点商品中
     * 匹配出来的明细，与商户真实下单明细（items）分开存放。
     */
    public function matchedItems(): HasMany
    {
        return $this->hasMany(OrderMatchedItem::class);
    }

    /**
     * hasOne 而不是 hasMany：迁移里 order_shippings.order_id 有唯一约束
     * （同一时间只允许一条有效物流记录，重复提交按业务规则走 update）。
     */
    public function shipping(): HasOne
    {
        return $this->hasOne(OrderShipping::class);
    }

    /**
     * order_events 通过 order_no（而不是 id）关联，且不加数据库外键约束，
     * 因为这张表镜像的是外部系统数据，写入时机可能早于/独立于本地订单的生命周期。
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'order_no', 'order_no');
    }

    public function notificationAttempts(): HasMany
    {
        return $this->hasMany(OrderNotificationAttempt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }

    public function disputeEvents(): HasMany
    {
        return $this->hasMany(OrderDisputeEvent::class);
    }

    /**
     * 当前处理中的争议审核事件（至多一条，由 order_dispute_events 的
     * 生成列唯一约束在数据库层保证），可安全 with() 预加载。
     */
    public function activeDisputeEvent(): HasOne
    {
        return $this->hasOne(OrderDisputeEvent::class)->where('status', OrderDisputeEvent::STATUS_PROCESSING);
    }

    /**
     * 已锁定的支付方式（订单 payment_method 存的是 method_code，按商户 + code 反查）。
     * 用于取该支付方式配置的退款/拒付手续费。
     */
    public function paymentMethodConfig(): ?PaymentMethod
    {
        if (empty($this->payment_method)) {
            return null;
        }

        return PaymentMethod::query()
            ->withoutGlobalScopes()
            ->where('merchant_id', $this->merchant_id)
            ->where('method_code', $this->payment_method)
            ->first();
    }

    /**
     * 剩余可退金额（订单原币种）= amount - 已累计退款。
     */
    public function refundableAmount(): string
    {
        return bcsub((string) $this->amount, (string) $this->refunded_amount, 2);
    }

    // ------------------------------------------------------------------
    // 查询作用域
    // ------------------------------------------------------------------

    /**
     * 已付款但尚未发货的订单（发货状态自动机的核心查询：物流批量导入 /
     * 手动发货入口都要基于这个作用域去匹配可发货订单）。
     */
    public function scopePaidUnshipped(Builder $query): Builder
    {
        return $query->where('status', 'paid')->doesntHave('shipping');
    }

    /**
     * 付款链接当前是否仍然可访问：订单状态为 pending，且未超过有效期。
     * expire_days 从 system_configs（payment_link.expire_days）读取。
     */
    public function scopeValidPaymentLink(Builder $query): Builder
    {
        $expireDays = (int) SystemConfig::get('payment_link.expire_days', 7);

        return $query->where('status', 'pending')
            ->where('created_at', '>', now()->subDays($expireDays));
    }

    // ------------------------------------------------------------------
    // 业务方法
    // ------------------------------------------------------------------

    /**
     * 当前允许的电商网站平台类型枚举（下单校验、后台表单选项共用）。
     */
    public static function supportedPlatforms(): array
    {
        return SystemConfig::getArray('order.platforms', self::PLATFORMS_FALLBACK);
    }

    /**
     * 金额公式铁律校验：amount = subtotal + shipping_fee - discount + tax，
     * 误差超过 AMOUNT_TOLERANCE 视为不合法。使用 bcmath 避免浮点误差。
     */
    public static function isAmountValid(
        string|float $subtotal,
        string|float $shippingFee,
        string|float $discount,
        string|float $tax,
        string|float $amount
    ): bool {
        $expected = bcadd((string) $subtotal, (string) $shippingFee, 4);
        $expected = bcsub($expected, (string) $discount, 4);
        $expected = bcadd($expected, (string) $tax, 4);

        $diff = abs((float) bcsub($expected, (string) $amount, 4));

        return $diff <= self::AMOUNT_TOLERANCE;
    }

    /**
     * 系统订单号：ORD + yyyymmdd + 随机段。唯一性最终由数据库唯一索引
     * （order_no_uniq 生成列）兜底，配合 createWithGeneratedIdentifiers()
     * 的冲突重试使用。
     *
     * 注意：这不是真正的 Snowflake ID，只是格式上向其对齐（有序前缀 + 随机段）。
     * 如果未来需要严格递增、可反解时间戳的 ID，建议换成
     * godruoyi/php-snowflake 之类的专业实现。
     */
    public static function generateOrderNo(): string
    {
        return 'ORD'.now()->format('Ymd').now()->format('His').random_int(1000, 9999);
    }

    /**
     * 付款链接令牌：不暴露订单号，纯随机字符串。
     */
    public static function generatePaymentLinkToken(): string
    {
        return 'PL_'.Str::random(24).'_'.now()->format('YmdHis');
    }

    /**
     * 推荐的创建入口：自动生成 order_no / payment_link_token，
     * 若命中唯一索引冲突（极小概率的同毫秒碰撞）自动重新生成并重试。
     */
    public static function createWithGeneratedIdentifiers(array $attributes, int $maxAttempts = 3): self
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return static::create(array_merge($attributes, [
                    'order_no' => static::generateOrderNo(),
                    'payment_link_token' => static::generatePaymentLinkToken(),
                ]));
            } catch (QueryException $e) {
                $isDuplicateKey = ((string) $e->getCode()) === '23000';

                if (! $isDuplicateKey || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        // 理论上不可达，仅为满足静态分析对返回类型的要求。
        throw new \RuntimeException('Failed to create order after '.$maxAttempts.' attempts.');
    }

    /**
     * 付款链接是否已过期/失效（订单状态非 pending，或超过有效期）。
     */
    public function isPaymentLinkExpired(): bool
    {
        if ($this->status !== 'pending') {
            return true;
        }

        $expireDays = (int) SystemConfig::get('payment_link.expire_days', 7);

        return $this->created_at->lt(now()->subDays($expireDays));
    }
}

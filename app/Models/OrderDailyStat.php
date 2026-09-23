<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 订单每日统计（商户 × 应用 × 支付方式），见 create_order_daily_stats_table 迁移。
 *
 * 刻意**不挂** BelongsToMerchant / MerchantScope：这是后台看板专用的派生数据，
 * 读取范围由调用方用 DashboardService::viewerMerchantIds() 显式表达
 * （与 DashboardService 的做法一致），写入方是没有登录态的定时命令。
 * 挂上全局作用域反而会在"从 Filament 动作触发重算"这种场景下悄悄只统计
 * 操作人名下的商户。
 */
class OrderDailyStat extends Model
{
    /** 指标列，供聚合与看板共用，避免两处各写一份列名清单。 */
    public const METRIC_COLUMNS = [
        'paid_orders', 'paid_amount',
        'failed_orders', 'failed_amount',
        'refunded_orders', 'refunded_amount',
        'chargeback_orders', 'chargeback_amount',
    ];

    protected $fillable = [
        'stat_date',
        'merchant_id',
        'application_id',
        'payment_method_id',
        ...self::METRIC_COLUMNS,
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'merchant_id' => 'integer',
            'application_id' => 'integer',
            'payment_method_id' => 'integer',
            'paid_orders' => 'integer',
            'paid_amount' => 'decimal:2',
            'failed_orders' => 'integer',
            'failed_amount' => 'decimal:2',
            'refunded_orders' => 'integer',
            'refunded_amount' => 'decimal:2',
            'chargeback_orders' => 'integer',
            'chargeback_amount' => 'decimal:2',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * withTrashed()：支付方式可能在统计之后被软删除，历史统计行仍应能显示
     * 当时的渠道名称（口径同 Order::paymentMethod()）。
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }

    /**
     * 限定统计范围。语义与 User::manageableMerchantIds() 完全一致：
     * null = 不限（仅超级管理员），数组 = 只统计这些商户，空数组 = 什么都看不到。
     *
     * @param  array<int>|null  $merchantIds
     */
    public function scopeForViewer(Builder $query, ?array $merchantIds): Builder
    {
        return $merchantIds === null ? $query : $query->whereIn('merchant_id', $merchantIds);
    }
}

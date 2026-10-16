<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 新订单的可靠记账待办，与状态变更同事务创建，成功记录永久保留用于审计。
 * merchant_id 有意不设置外键：状态事务先锁订单，外键检查商户行会反转资金锁序。
 * 执行时必须核对订单与待办的商户一致，并按商户、订单、待办顺序加锁。
 */
class OrderAccountingTask extends Model
{
    use BelongsToMerchant;

    public const TYPE_PAID = 'paid';

    public const TYPE_REFUND = 'refund';

    public const TYPE_CHARGEBACK = 'chargeback';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MANUAL_REVIEW = 'manual_review';

    protected $fillable = [
        'merchant_id', 'order_id', 'type', 'status', 'attempts',
        'last_attempted_at', 'next_attempt_at', 'completed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** 实际本金尚未扣足的反向待办；人工已补录的不再拦截出款。 */
    public function scopeUnsettledReversals(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_MANUAL_REVIEW])
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $refund) => $refund->where('type', self::TYPE_REFUND)
                    ->whereHas('order', fn (Builder $orders) => $orders->withoutGlobalScopes()->whereColumn('refunded_amount', '<', 'amount')))
                    ->orWhere(fn (Builder $chargeback) => $chargeback->where('type', self::TYPE_CHARGEBACK)
                        ->whereHas('order', fn (Builder $orders) => $orders->withoutGlobalScopes()
                            ->whereDoesntHave('balanceTransactions', fn (Builder $transactions) => $transactions->withoutGlobalScopes()
                                ->where('type', MerchantBalanceTransaction::TYPE_CHARGEBACK))));
            });
    }
}

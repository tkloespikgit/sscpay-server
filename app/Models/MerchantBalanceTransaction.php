<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商户余额流水台账（不可变）。每一次对"总余额"的增减都落一条，
 * 由 BalanceService 在事务 + 行锁内写入，禁止直接改动/删除。
 *
 * 冻结/释放（提现审核期间）不改变总余额，因此不落这张表，
 * 相关审计由 MerchantWithdrawal 的状态流转承担。
 */
class MerchantBalanceTransaction extends Model
{
    use BelongsToMerchant;

    public const TYPE_ORDER_PAID = 'order_paid';

    public const TYPE_REFUND = 'refund';

    public const TYPE_REFUND_FEE = 'refund_fee';

    public const TYPE_CHARGEBACK = 'chargeback';

    public const TYPE_CHARGEBACK_FEE = 'chargeback_fee';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_MANUAL_ADJUST = 'manual_adjust';

    protected $fillable = [
        'merchant_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'order_id',
        'order_refund_id',
        'withdrawal_id',
        'operator_id',
        'reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class, 'order_refund_id');
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(MerchantWithdrawal::class, 'withdrawal_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}

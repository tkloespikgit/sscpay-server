<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商户余额流水台账（不可变）。每一次对"总余额"的增减都落一条，
 * 由 BalanceService 在事务 + 行锁内写入，禁止直接改动/删除。
 *
 * 账户分类划转同样记录，amount 为 0，各分类 *_change 记录去向。
 * 旧冻结/提现占用的历史流水不回填，沿用原业务单据追溯。
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

    public const TYPE_ORDER_SETTLEMENT = 'order_settlement';

    public const TYPE_RESERVE_FREEZE = 'reserve_freeze';

    public const TYPE_RESERVE_RELEASE = 'reserve_release';

    public const TYPE_WITHDRAWAL_REQUEST = 'withdrawal_request';

    public const TYPE_WITHDRAWAL_REJECTED = 'withdrawal_rejected';

    public const TYPE_DISPUTE_FREEZE = 'dispute_freeze';

    public const TYPE_DISPUTE_RELEASE = 'dispute_release';

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
        'pending_settlement_change',
        'pending_withdrawal_change',
        'frozen_change',
        'fund_freeze_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'pending_settlement_change' => 'decimal:2',
            'pending_withdrawal_change' => 'decimal:2',
            'frozen_change' => 'decimal:2',
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

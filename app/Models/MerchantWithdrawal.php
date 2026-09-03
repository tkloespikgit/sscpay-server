<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商户提现单。生命周期：
 *   pending  申请中——金额已从可用余额冻结（balance 不变，frozen_balance += amount）
 *   approved 审核通过并放款——冻结转出（balance -= amount，frozen_balance -= amount），落一条 withdrawal 流水
 *   rejected 驳回——释放冻结（frozen_balance -= amount），可用余额恢复
 *
 * 冻结/释放不产生余额流水，审计以本表的状态 + reviewed_by/reviewed_at 为准。
 */
class MerchantWithdrawal extends Model
{
    use BelongsToMerchant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'merchant_id',
        'amount',
        'status',
        'payout_account',
        'remark',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_remark',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

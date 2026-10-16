<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商户提现单。生命周期：
 *   pending  申请中——新单占用 pending_withdrawal_balance，旧单继续占用 frozen_balance。
 *   approved 后台确认放款——扣除总余额及对应占用额。
 *   rejected 驳回——释放对应占用额，可提现余额恢复，总余额不变。
 *
 * 当前操作同时保存分类划转流水；旧台账不回填。
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
        'uses_pending_balance',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'uses_pending_balance' => 'boolean',
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

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 固定保证金及订单循环保证金，order_id 区分来源。生命周期：
 *
 *   frozen 冻结中 —— 已计入 merchants.frozen_balance；release_at 有值时
 *     等到期由 fund-freezes:release-due 命令自动释放，否则只能人工解冻。
 *   released 已解冻 —— 冻结已释放，release_type 区分 manual（人工，
 *     released_by 有值）/ auto（到期系统自动解冻，released_by 为 NULL）。
 *
 * consumed 为循环保证金已被退款或拒付抵扣；amount 保留原额，remaining_amount
 * 保存未释放/未抵扣余额。旧记录 remaining_amount 为 NULL 时回退 amount。
 * 新操作写分类划转流水，旧台账不回填。
 */
class MerchantFundFreeze extends Model
{
    use BelongsToMerchant;

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CONSUMED = 'consumed';

    public const RELEASE_TYPE_MANUAL = 'manual';

    public const RELEASE_TYPE_AUTO = 'auto';

    protected $fillable = [
        'merchant_id',
        'order_id',
        'amount',
        'remaining_amount',
        'status',
        'reason',
        'release_at',
        'frozen_by',
        'frozen_at',
        'released_by',
        'released_at',
        'release_type',
        'release_remark',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'release_at' => 'datetime',
            'frozen_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function remainingAmount(): string
    {
        return (string) ($this->remaining_amount ?? $this->amount);
    }

    public function frozenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_FROZEN;
    }

    /**
     * 已到期仍冻结中、且设置了计划解冻时间的记录：供自动解冻 sweep 使用。
     * release_at 为 NULL 的记录（只能人工解冻）永远不会被这个 scope 选中。
     */
    public function scopeDueForAutoRelease(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FROZEN)
            ->whereNotNull('release_at')
            ->where('release_at', '<=', now());
    }
}

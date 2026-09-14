<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 通用人工资金冻结：商户管理员/超级管理员对商户直接冻结一笔资金
 * （见 BalanceService::freezeFunds()），与订单/提现无关。生命周期：
 *
 *   frozen 冻结中 —— 已计入 merchants.frozen_balance；release_at 有值时
 *     等到期由 fund-freezes:release-due 命令自动释放，否则只能人工解冻。
 *   released 已解冻 —— 冻结已释放，release_type 区分 manual（人工，
 *     released_by 有值）/ auto（到期系统自动解冻，released_by 为 NULL）。
 *
 * 冻结/释放不产生 merchant_balance_transactions 流水（同提现/争议审核冻结
 * 的既有约定），审计以本表状态 + frozen_by/frozen_at/released_by/released_at 为准。
 */
class MerchantFundFreeze extends Model
{
    use BelongsToMerchant;

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_RELEASED = 'released';

    public const RELEASE_TYPE_MANUAL = 'manual';

    public const RELEASE_TYPE_AUTO = 'auto';

    protected $fillable = [
        'merchant_id',
        'amount',
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
            'release_at' => 'datetime',
            'frozen_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
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

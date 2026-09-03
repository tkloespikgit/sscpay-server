<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 人工发起的订单争议审核事件：财务管理员对一笔已付款订单开立，冻结该笔
 * 订单金额，交由订单管理员回复补充材料，最终人工手动或到期自动结束
 * （释放冻结资金，订单状态恢复 paid）。生命周期：
 *
 *   processing 处理中 —— 已冻结 frozen_amount（USD），订单状态为
 *     Order::STATUS_DISPUTE_REVIEW，同一订单同时只能有一条（见迁移里的
 *     生成列唯一约束）。
 *   closed 已结束 —— 冻结已释放，订单状态回退 paid。close_type 区分
 *     manual（人工，closed_by 有值）/ auto（到期系统自动关闭，closed_by 为 NULL）。
 *
 * 冻结/释放不产生 merchant_balance_transactions 流水（同提现冻结的既有约定），
 * 审计以本表状态 + opened_by/opened_at/closed_by/closed_at 为准。
 * final_action（拟定退款/拒付）仅作报备记录，不自动触发任何资金动作——
 * 真正的退款/拒付仍需之后单独走 BalanceService::refund()/chargeback()。
 */
class OrderDisputeEvent extends Model
{
    use BelongsToMerchant;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_CLOSED = 'closed';

    public const CLOSE_TYPE_MANUAL = 'manual';

    public const CLOSE_TYPE_AUTO = 'auto';

    public const FINAL_ACTION_REFUND = 'refund';

    public const FINAL_ACTION_CHARGEBACK = 'chargeback';

    public const DEADLINE_UNIT_HOURS = 'hours';

    public const DEADLINE_UNIT_DAYS = 'days';

    protected $fillable = [
        'merchant_id',
        'order_id',
        'order_no',
        'payment_method',
        'event_no',
        'status',
        'reason',
        'images',
        'final_action',
        'deadline_value',
        'deadline_unit',
        'deadline_hours',
        'frozen_amount',
        'opened_by',
        'opened_at',
        'due_at',
        'reminded_at',
        'closed_by',
        'closed_at',
        'close_type',
        'close_remark',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'deadline_value' => 'integer',
            'deadline_hours' => 'integer',
            'frozen_amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'due_at' => 'datetime',
            'reminded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(OrderDisputeEventReply::class)->orderBy('created_at');
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * 已到期仍处理中的事件：供自动关闭 sweep 使用。
     */
    public function scopeDueForAutoClose(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING)
            ->where('due_at', '<=', now());
    }

    /**
     * 24 小时内到期、尚未处理中、还没发过提醒的事件：供到期前提醒 sweep 使用。
     * 下边界排除已经过期的（那些交给 dueForAutoClose 处理，不重复提醒）。
     */
    public function scopeDueForReminder(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING)
            ->whereNull('reminded_at')
            ->where('due_at', '>', now())
            ->where('due_at', '<=', now()->addHours(24));
    }
}

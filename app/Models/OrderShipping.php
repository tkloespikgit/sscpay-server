<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderShipping extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    /** 尚未尝试同步给 WordPress 商城系统插件（或内容已变更，等待重新同步）。 */
    public const SYNC_STATUS_PENDING = 'pending';

    /** 插件确认已同步（含转发给支付渠道方成功，见 doc/s-system-sync-tracking.md 第五节 synced_to_remote）。 */
    public const SYNC_STATUS_SYNCED = 'synced';

    /** 本地调用失败，或插件明确同步失败/该渠道不支持转发。 */
    public const SYNC_STATUS_FAILED = 'failed';

    public const SYNC_STATUSES = [self::SYNC_STATUS_PENDING, self::SYNC_STATUS_SYNCED, self::SYNC_STATUS_FAILED];

    protected $fillable = [
        'order_id',
        'merchant_id',
        'logistics_company',
        'tracking_number',
        'tracking_url',
        'shipped_at',
        'operator_id',
        'remark',
        'sync_status',
        'sync_message',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * 推荐的写入入口：无论是批量物流导入、手动发货入口，还是任何未来的调用方，
     * 都应该走这个方法而不是裸的 create()/update()——
     * 数据库有 order_id 的唯一约束（同一时间只允许一条有效物流记录），
     * 这里用 updateOrCreate 把"重复提交=更新原记录（补发/改单）"的业务规则
     * 落到应用层，和数据库约束互为保险。
     *
     * 触发 OrderShippingObserver 后会自动把订单状态推进为 shipped。
     *
     * 无论调用方传了什么，同步状态三个字段（sync_status/sync_message/synced_at）
     * 在这里总是被强制重置为"待同步"——内容变了（哪怕只是补发同一个单号）就应该
     * 视为需要重新同步，不接受调用方绕过这个规则。触发同步动作是调用方
     * （OrderShippingService）的职责，本方法只负责落库。
     */
    public static function recordShipment(int $orderId, array $attributes): self
    {
        return static::updateOrCreate(
            ['order_id' => $orderId],
            array_merge($attributes, [
                'sync_status' => self::SYNC_STATUS_PENDING,
                'sync_message' => null,
                'synced_at' => null,
            ])
        );
    }
}

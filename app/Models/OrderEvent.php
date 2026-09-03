<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * order_events 镜像插件侧的订单日志，本系统不产生这些日志，只通过
 * OrderEventSyncService 按订单逐笔拉取 /order-logs 接口写入
 * （见 order-events:sync 命令）。Filament 后台里这个 Resource 应该做成
 * 只读，不提供增删改入口。
 */
class OrderEvent extends Model
{
    use BelongsToMerchant;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'order_no',
        'external_log_id',
        'level',
        'message',
        'payment_method',
        'wp_order_id',
        'request_payload',
        'response_payload',
        'callback_payload',
        'ip',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'wp_order_id' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'callback_payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * 通过 order_no（而不是 id）关联订单主表，且不加数据库外键约束，
     * 以兼容"插件侧日志先产生、本地订单可能尚未完全同步"等边界情况。
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_no', 'order_no');
    }

    /** 插件侧日志级别枚举，见 doc/s-system-payment-status-notify.md 第八节。 */
    public const LEVELS = ['INFO', 'WARNING', 'ERROR'];

    /**
     * 订单详情页专用：按 occurred_at 倒序展示某个订单的全部日志。
     */
    public static function timelineForOrder(string $orderNo)
    {
        return static::query()
            ->where('order_no', $orderNo)
            ->orderByDesc('occurred_at')
            ->get();
    }
}

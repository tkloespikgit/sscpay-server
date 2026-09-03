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

    protected $fillable = [
        'order_id',
        'merchant_id',
        'logistics_company',
        'tracking_number',
        'tracking_url',
        'shipped_at',
        'operator_id',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
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
     */
    public static function recordShipment(int $orderId, array $attributes): self
    {
        return static::updateOrCreate(
            ['order_id' => $orderId],
            $attributes
        );
    }
}

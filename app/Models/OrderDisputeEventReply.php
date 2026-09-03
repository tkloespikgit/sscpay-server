<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 争议审核事件的回复记录（append-only 线程），由商户交易订单管理员在
 * 事件处理中期间提交，富文本 + 图片，入库前已做 XSS 过滤（见
 * App\Support\RichTextSanitizer）。回复不会改变事件状态、也不会延长
 * 事件的到期时间（due_at 在开立时就已固定，见 OrderDisputeEvent）。
 */
class OrderDisputeEventReply extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'order_dispute_event_id',
        'merchant_id',
        'content',
        'images',
        'operator_id',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrderDisputeEvent::class, 'order_dispute_event_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}

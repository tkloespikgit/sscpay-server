<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 物流批量导入的逐行明细，见 create_logistics_import_task_records_table 迁移的说明。
 *
 * 不加软删除：明细行跟着任务走，任务被物理删除时由 task_id 的 cascadeOnDelete 清理。
 */
class LogisticsImportTaskRecord extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    /** 已从 CSV 读出落库，尚未执行物流同步。 */
    public const STATUS_PENDING = 'pending';

    /** 物流信息落库成功（并已投递插件同步任务）。 */
    public const STATUS_SUCCESS = 'success';

    /** 同步失败，原因见 error_message。 */
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SUCCESS, self::STATUS_FAILED];

    protected $fillable = [
        'task_id',
        'order_id',
        'merchant_id',
        'row_number',
        'order_no',
        'logistics_company',
        'tracking_number',
        'remark',
        'raw_data',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'order_id' => 'integer',
            'row_number' => 'integer',
            'raw_data' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(LogisticsImportTask::class, 'task_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function markSuccess(?int $orderId = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCESS,
            'order_id' => $orderId,
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $message, ?int $orderId = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'order_id' => $orderId,
            'error_message' => $message,
        ])->save();
    }
}

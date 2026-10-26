<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 物流批量导入任务。
 *
 * 两个文件名要分清楚：
 *
 *   - original_file_name：商户本地上传时的文件名（如 我的物流单.csv），只用于展示；
 *   - file_name：保存文件名，即 OSS 上的对象名，Filament 生成的随机串。
 *
 * 2026-10-22 之前没有 original_file_name，历史记录该列为 NULL，展示时用
 * displayFileName() 回退到 file_name。
 */
class LogisticsImportTask extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    /** 已落库，等待 worker 取走。没有 worker 时任务会停在这里，见 README 的队列说明。 */
    public const STATUS_PENDING = 'pending';

    /** worker 已开始解析（started_at 已写）。长期停在此状态说明 worker 中断，见 isStale()。 */
    public const STATUS_PROCESSING = 'processing';

    /** 处理结束。注意 fail_count 可能大于 0（部分成功），不能等同于"全部成功"。 */
    public const STATUS_COMPLETED = 'completed';

    /** 文件级失败（OSS 下载失败 / 无表头 / 缺 order_no 列），一条明细都没落库。 */
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /** processing 超过这个分钟数即认为 worker 已中断。 */
    public const STALE_AFTER_MINUTES = 30;

    protected $fillable = [
        'merchant_id',
        'operator_id',
        'file_name',
        'original_file_name',
        'oss_path',
        'status',
        'total_records',
        'success_count',
        'fail_count',
        'error_log',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'total_records' => 'integer',
            'success_count' => 'integer',
            'fail_count' => 'integer',
            'error_log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * 逐行导入明细：文件里的每一行都会落一条记录，失败原因记在
     * LogisticsImportTaskRecord::error_message 上（本表的 error_log 只保留前若干条
     * 摘要，完整明细看这里）。
     */
    public function records(): HasMany
    {
        return $this->hasMany(LogisticsImportTaskRecord::class, 'task_id');
    }

    /**
     * 展示用的上传文件名：优先原始文件名，历史数据（该列为 NULL）回退到保存文件名。
     */
    public function displayFileName(): string
    {
        return (string) (filled($this->original_file_name) ? $this->original_file_name : $this->file_name);
    }

    /**
     * 是否是一条"历史记录"（上传时就还没存原始文件名）。
     * 列表页据此加一个灰色标记，避免运维把随机名当成商户填错。
     */
    public function hasLegacyFileName(): bool
    {
        return blank($this->original_file_name);
    }

    /**
     * worker 中断判定：停在 processing 且超过 STALE_AFTER_MINUTES 没有结束。
     *
     * 覆盖两种真实场景：worker 被 kill、或者 DB 连接断开导致 Job 静默退出。
     * 这两种情况下任务永远不会再被处理（Job 已被取走，不满足重试条件），
     * 页面上必须给出提示，否则商户只会看到"解析中"一直转。
     *
     * 注意 started_at 为空也按卡死处理：老数据或异常路径下可能只有 status 没有
     * 时间戳，这时用 updated_at 兜底。
     */
    public function isStale(): bool
    {
        if ($this->status !== self::STATUS_PROCESSING) {
            return false;
        }

        $since = $this->started_at ?? $this->updated_at;

        return $since !== null && $since->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * 标记开始解析。重跑（Job 重试）时刷新 started_at 并清掉上一次的 finished_at，
     * 否则页面上会出现"已结束"却又在跑的矛盾状态。
     */
    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
            'finished_at' => null,
        ])->save();
    }

    public function markCompleted(int $successCount, int $failCount, array $errorLog = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'error_log' => $errorLog ?: null,
        ]);
    }

    public function markFailed(array $errorLog = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_log' => $errorLog ?: null,
        ]);
    }

    /** 统一收尾时间。markCompleted/markFailed 都会走到 LogisticsImportService::finish()。 */
    public function markFinished(): void
    {
        $this->forceFill(['finished_at' => now()])->save();
    }
}

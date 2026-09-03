<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商户交易结果通知（回调 orders.notify_url）的单次尝试记录。
 *
 * 重试策略（最多 5 次尝试）：
 *   第1次失败 → 30 秒后重试
 *   第2次失败 → 5 分钟后重试
 *   第3次失败 → 30 分钟后重试
 *   第4次失败 → 1 小时后重试（第5次，最后一次）
 *   第5次仍失败 → exhausted，不再重试
 *
 * 重试间隔从 system_configs（notify.retry_intervals_seconds）读取，方便后台调整
 * 而不用改代码。设计上采用"惰性创建下一行"：某次尝试失败后，当前行状态置为
 * failed 并写入 next_retry_at；由调度任务扫描 dueForRetry() 找到到期的失败记录，
 * 再调用 createNextAttempt() 生成并发送下一次尝试，而不是一次性预先插入 5 行。
 */
class OrderNotificationAttempt extends Model
{
    use BelongsToMerchant;
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'merchant_id',
        'notify_type',
        'attempt_number',
        'max_attempts',
        'status',
        'notify_url',
        'request_payload',
        'request_headers',
        'response_status_code',
        'response_body',
        'error_message',
        'duration_ms',
        'scheduled_at',
        'attempted_at',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'max_attempts' => 'integer',
            'request_payload' => 'array',
            'request_headers' => 'array',
            'response_status_code' => 'integer',
            'duration_ms' => 'integer',
            'scheduled_at' => 'datetime',
            'attempted_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    // ------------------------------------------------------------------
    // 配置读取
    // ------------------------------------------------------------------

    public static function configuredMaxAttempts(): int
    {
        return (int) SystemConfig::get('notify.max_attempts', 5);
    }

    /**
     * @return int[] 依次对应"第 N 次失败后，等待多少秒进行第 N+1 次尝试"。
     *               默认 [30, 300, 1800, 3600]，对应 30秒/5分钟/30分钟/1小时。
     */
    public static function configuredRetryIntervals(): array
    {
        return SystemConfig::getArray('notify.retry_intervals_seconds', [30, 300, 1800, 3600]);
    }

    public static function responseBodyMaxLength(): int
    {
        return (int) SystemConfig::get('notify.response_body_max_length', 5000);
    }

    // ------------------------------------------------------------------
    // 创建
    // ------------------------------------------------------------------

    /**
     * 创建一次通知的首次尝试记录（attempt_number = 1）。
     */
    public static function createInitialAttempt(Order $order, string $notifyType, array $requestPayload): self
    {
        return static::create([
            'order_id' => $order->id,
            'merchant_id' => $order->merchant_id,
            'notify_type' => $notifyType,
            'attempt_number' => 1,
            'max_attempts' => static::configuredMaxAttempts(),
            'status' => 'pending',
            'notify_url' => $order->notify_url,
            'request_payload' => $requestPayload,
            'scheduled_at' => now(),
        ]);
    }

    /**
     * 基于当前这条"失败且等待重试"的记录，生成下一次尝试。
     * 调用方（调度任务）应在拿到返回值后立即发起实际的 HTTP 请求。
     */
    public function createNextAttempt(): self
    {
        return static::create([
            'order_id' => $this->order_id,
            'merchant_id' => $this->merchant_id,
            'notify_type' => $this->notify_type,
            'attempt_number' => $this->attempt_number + 1,
            'max_attempts' => $this->max_attempts,
            'status' => 'pending',
            'notify_url' => $this->notify_url,
            'request_payload' => $this->request_payload,
            'scheduled_at' => $this->next_retry_at ?? now(),
        ]);
    }

    // ------------------------------------------------------------------
    // 状态流转
    // ------------------------------------------------------------------

    public function markSuccess(int $statusCode, ?string $responseBody, ?int $durationMs = null): void
    {
        $this->update([
            'status' => 'success',
            'response_status_code' => $statusCode,
            'response_body' => $this->truncateResponseBody($responseBody),
            'error_message' => null,
            'duration_ms' => $durationMs,
            'attempted_at' => now(),
            'next_retry_at' => null,
        ]);
    }

    /**
     * @param  int|null  $statusCode  收到了响应但状态码不符合"成功"判定时传入；
     *                                完全没收到响应（超时/连接失败）时传 null 并用 $errorMessage 说明原因。
     */
    public function markFailed(?int $statusCode, ?string $responseBody, ?string $errorMessage, ?int $durationMs = null): void
    {
        $hasMoreAttempts = $this->attempt_number < $this->max_attempts;

        $update = [
            'response_status_code' => $statusCode,
            'response_body' => $this->truncateResponseBody($responseBody),
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'attempted_at' => now(),
        ];

        if ($hasMoreAttempts) {
            $intervals = static::configuredRetryIntervals();
            $index = $this->attempt_number - 1; // 第1次失败 -> intervals[0]，第2次失败 -> intervals[1]，以此类推
            $intervalSeconds = $intervals[$index] ?? (end($intervals) ?: 3600);

            $update['status'] = 'failed';
            $update['next_retry_at'] = now()->addSeconds($intervalSeconds);
        } else {
            $update['status'] = 'exhausted';
            $update['next_retry_at'] = null;
        }

        $this->update($update);
    }

    private function truncateResponseBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $maxLength = static::responseBodyMaxLength();

        return mb_strlen($body) > $maxLength ? mb_substr($body, 0, $maxLength) : $body;
    }

    // ------------------------------------------------------------------
    // 查询作用域
    // ------------------------------------------------------------------

    /**
     * 供调度任务扫描：已失败、未耗尽重试次数、且已到重试时间的记录。
     */
    public function scopeDueForRetry(Builder $query): Builder
    {
        return $query->where('status', 'failed')
            ->where('next_retry_at', '<=', now());
    }

    /**
     * 订单详情页展示用：某订单全部尝试记录，按尝试顺序排列。
     */
    public static function timelineForOrder(int $orderId, string $notifyType = 'trade_result')
    {
        return static::query()
            ->where('order_id', $orderId)
            ->where('notify_type', $notifyType)
            ->orderBy('attempt_number')
            ->get();
    }
}

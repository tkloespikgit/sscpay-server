<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 广告转化通知（订单支付成功后告知 Meta/Google/TikTok 等广告平台）的单次尝试记录。
 * 与 OrderNotificationAttempt 完全对称，见该类注释的重试策略说明；这里的
 * "通知对象"是广告平台的转化 API，用 platform 字段区分，而不是商户的 notify_url。
 */
class AdConversionAttempt extends Model
{
    use BelongsToMerchant;
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'merchant_id',
        'platform',
        'attempt_number',
        'max_attempts',
        'status',
        'request_payload',
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
        return (int) SystemConfig::get('ad_conversion.max_attempts', 5);
    }

    /**
     * @return int[] 依次对应"第 N 次失败后，等待多少秒进行第 N+1 次尝试"。
     *               默认 [30, 300, 1800, 3600]，对应 30秒/5分钟/30分钟/1小时。
     */
    public static function configuredRetryIntervals(): array
    {
        return SystemConfig::getArray('ad_conversion.retry_intervals_seconds', [30, 300, 1800, 3600]);
    }

    public static function responseBodyMaxLength(): int
    {
        return (int) SystemConfig::get('ad_conversion.response_body_max_length', 5000);
    }

    // ------------------------------------------------------------------
    // 创建
    // ------------------------------------------------------------------

    /**
     * 创建一次广告转化通知的首次尝试记录（attempt_number = 1）。
     *
     * @param  array  $requestPayload  发给该平台转化 API 的完整请求体快照
     */
    public static function createInitialAttempt(Order $order, string $platform, array $requestPayload): self
    {
        return static::create([
            'order_id' => $order->id,
            'merchant_id' => $order->merchant_id,
            'platform' => $platform,
            'attempt_number' => 1,
            'max_attempts' => static::configuredMaxAttempts(),
            'status' => 'pending',
            'request_payload' => $requestPayload,
            'scheduled_at' => now(),
        ]);
    }

    /**
     * 基于当前这条"失败且等待重试"的记录，生成下一次尝试。
     */
    public function createNextAttempt(): self
    {
        return static::create([
            'order_id' => $this->order_id,
            'merchant_id' => $this->merchant_id,
            'platform' => $this->platform,
            'attempt_number' => $this->attempt_number + 1,
            'max_attempts' => $this->max_attempts,
            'status' => 'pending',
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
     *                                完全没收到响应（超时/连接失败/凭证缺失）时传 null 并用 $errorMessage 说明原因。
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
     * 订单详情页展示用：某订单全部尝试记录，按平台+尝试顺序排列。
     */
    public static function timelineForOrder(int $orderId)
    {
        return static::query()
            ->where('order_id', $orderId)
            ->orderBy('platform')
            ->orderBy('attempt_number')
            ->get();
    }
}

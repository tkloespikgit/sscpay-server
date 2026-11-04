<?php

namespace App\Listeners;

use App\Events\LogisticsAnomalyDetected;
use App\Models\TelegramBot;
use App\Services\TelegramNotificationService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;

class SendLogisticsAnomalyNotification implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public int $backoff = 60;

    public function __construct(private readonly TelegramNotificationService $telegram) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(LogisticsAnomalyDetected $event): void
    {
        $status = $event->details['status'] ?? '';
        $statusKey = 'admin.order.tracking_statuses.'.$status;
        $statusLabel = $status === '' ? __('admin.order.placeholders.no_tracking_status') : __($statusKey);
        $reason = $event->details['reason'] ?? '';
        $reasonKey = 'admin.order.logistics_anomaly_reasons.'.$reason;
        $reasonLabel = __($reasonKey, ['days' => mb_substr($event->details['days'] ?? '', 0, 20)]);
        $details = [
            'order_no' => $event->details['order_no'] ?? '-',
            'merchant_order_no' => $event->details['merchant_order_no'] ?? '-',
            'tracking_number' => $event->details['tracking_number'] ?? '-',
            'status' => $statusLabel === $statusKey ? $status : $statusLabel,
            'reason' => $reasonLabel === $reasonKey ? $reason : $reasonLabel,
        ];
        $details = array_map(
            fn (string $value): string => htmlspecialchars(mb_substr($value, 0, 100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $details,
        );

        if ($this->telegram->send($event->merchantId, __('admin.telegram_notification.logistics_anomaly', $details))) {
            return;
        }

        $bot = TelegramBot::query()->forMerchant($event->merchantId)->first();

        if ($bot?->isUsable()) {
            $this->release(60);
        }
    }
}

<?php

namespace App\Listeners;

use App\Events\LogisticsAnomalyDetected;
use App\Services\TelegramNotificationRouter;
use App\Support\OrderTelegramSummary;
use App\Support\TelegramNotificationKind;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;

class SendLogisticsAnomalyNotification implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public int $backoff = 60;

    public function __construct(private readonly TelegramNotificationRouter $telegramRoutes) {}

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

        $message = __('admin.telegram_notification.logistics_anomaly', $details)
            ."\n\n".OrderTelegramSummary::render($event->details);
        $methodId = $event->details['payment_method_id'] ?? null;
        $delivery = $this->telegramRoutes->send(
            TelegramNotificationKind::LogisticsAnomaly,
            $message,
            $event->merchantId,
            paymentMethodId: is_numeric($methodId) ? (int) $methodId : null,
        );

        if ($delivery->sent || $delivery->suppressed || ! $delivery->anyUsable) {
            return;
        }

        $this->release(60);
    }
}

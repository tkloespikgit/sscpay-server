<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** 按渠道记录建单最终结果，共享渠道的计数和自动禁用均为全局状态。 */
final readonly class PaymentMethodFailureService
{
    public function __construct(private TelegramNotificationService $telegram) {}

    public function recordFailure(Order $order, PaymentMethod $method, string $reference): void
    {
        $disabled = $this->recordResult($order, $method, $reference, false);

        if ($disabled === null) {
            return;
        }

        $method = $disabled['method'];
        Log::warning('支付通道连续建单失败达到阈值，自动禁用', [
            'order_no' => $order->order_no,
            'merchant_id' => $order->merchant_id,
            'payment_method_id' => $method->id,
            'consecutive_failed_times' => $method->consecutive_failed_times,
            'max_failed_times' => $disabled['limit'],
        ]);

        try {
            $details = array_map(
                fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                [
                    'method_name' => $method->method_name,
                    'method_code' => $method->method_code,
                    'order_no' => $order->order_no,
                    'failed_times' => $method->consecutive_failed_times,
                    'max_failed_times' => $disabled['limit'],
                ],
            );
            $message = __('admin.telegram_notification.payment_method_creation_failed_disabled', $details);
            $recipients = array_unique([$order->merchant_id, ...$method->notifiableMerchantIds()]);

            foreach ($recipients as $merchantId) {
                try {
                    $this->telegram->send((int) $merchantId, $message);
                } catch (Throwable $error) {
                    Log::warning('支付通道连续失败禁用提醒发送失败', [
                        'payment_method_id' => $method->id,
                        'merchant_id' => $merchantId,
                        'exception' => $error::class,
                    ]);
                }
            }
        } catch (Throwable $error) {
            Log::warning('支付通道连续失败禁用提醒发送失败', [
                'payment_method_id' => $method->id,
                'exception' => $error::class,
            ]);
        }
    }

    public function recordSuccess(Order $order, PaymentMethod $method, string $reference): void
    {
        $this->recordResult($order, $method, $reference, true);
    }

    /**
     * 运行结果静默保存，避免触发配置编辑使用的渠道归属回填。
     *
     * @return array{method: PaymentMethod, limit: int}|null
     */
    private function recordResult(Order $order, PaymentMethod $method, string $reference, bool $succeeded): ?array
    {
        return DB::transaction(function () use ($order, $method, $reference, $succeeded): ?array {
            $lockedOrder = Order::query()->forMerchant($order->merchant_id)->lockForUpdate()->find($order->id);

            if (! $lockedOrder || $lockedOrder->payment_method_id !== $method->id
                || $lockedOrder->gatewayOrderReference() !== $reference) {
                return null;
            }

            if ($succeeded && blank($lockedOrder->pay_url)) {
                return null;
            }

            if (! $succeeded && (filled($lockedOrder->pay_url) || $lockedOrder->paid_at !== null
                || ! in_array($lockedOrder->status, ['pending', 'failed'], true))) {
                return null;
            }

            $lockedMethod = PaymentMethod::query()->forMerchant($lockedOrder->merchant_id)
                ->lockForUpdate()->find($method->id);

            if (! $lockedMethod) {
                return null;
            }

            if ($succeeded) {
                $lockedMethod->forceFill(['consecutive_failed_times' => 0])->saveQuietly();

                return null;
            }

            $failedTimes = min(4294967295, $lockedMethod->consecutive_failed_times + 1);
            $limit = $lockedMethod->maxFailedTimes();
            $disable = $lockedMethod->is_active && $failedTimes >= $limit;
            $lockedMethod->forceFill([
                'consecutive_failed_times' => $failedTimes,
                'is_active' => $disable ? false : $lockedMethod->is_active,
            ])->saveQuietly();

            return $disable ? ['method' => $lockedMethod, 'limit' => $limit] : null;
        }, 3);
    }
}

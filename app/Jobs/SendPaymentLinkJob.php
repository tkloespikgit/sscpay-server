<?php

namespace App\Jobs;

use App\Mail\PaymentLinkMail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPaymentLinkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 600];

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = Order::query()->withoutGlobalScopes()->with('application', 'merchant')->find($this->orderId);

        if (! $order) {
            return;
        }

        // 应用级邮件开关：关闭则跳过，不算失败（不重试）。
        if ($order->application && ! $order->application->is_order_email_enabled) {
            return;
        }

        if (! $order->customer_email) {
            return;
        }

        Mail::to($order->customer_email)->send(new PaymentLinkMail($order));

        $order->forceFill(['payment_link_sent_at' => now()])->save();
    }
}

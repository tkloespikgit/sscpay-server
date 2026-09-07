<?php

namespace App\Jobs;

use App\Mail\PaymentLinkMail;
use App\Models\Order;
use App\Support\MailerCredentials;
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

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('payment-links');
    }

    public function handle(): void
    {
        $order = Order::query()->withoutGlobalScopes()->with('application', 'merchant')->find($this->orderId);

        if (! $order) {
            return;
        }

        // 应用级邮件开关：关闭则跳过，不算失败（不重试），也不动 failed_reason——
        // 这是商户主动关掉通知，不是"配置缺失"，语义不一样。
        if ($order->application && ! $order->application->is_order_email_enabled) {
            return;
        }

        if (! $order->customer_email) {
            return;
        }

        // 锁定的支付方式与所属 Application 都没有配置可用的发信身份（sender_email +
        // 完整 ESP 凭证）时，直接标记失败、不发送、不回退平台自己的发信账号——
        // 平台没有自己的发信身份可用，这是业务铁律。不重试：这是静态配置问题，
        // 重试 3 次结果都一样，只会浪费队列时间，等管理员配好后点"立即重发"即可。
        $sender = $order->resolveMailSender();

        if (! $sender) {
            $order->forceFill([
                'payment_link_mail_failed_reason' => __('admin.mail_credentials.send_failed_no_sender'),
            ])->save();

            return;
        }

        $this->sendWithSender($order, $sender);

        $order->forceFill([
            'payment_link_sent_at' => now(),
            'payment_link_mail_failed_reason' => null,
        ])->save();
    }

    /**
     * 用解析出来的发信身份（锁定支付方式 or 所属 Application 二选一，见
     * Order::resolveMailSender()）临时把 ESP 凭证覆写进 mail.mailers.{driver}
     * 后发送。config() 覆写的是进程级全局状态，队列 worker 是长驻进程、会连续
     * 处理不同商户的任务，所以无论发送成功与否都要在 finally 里把
     * mail.mailers.{driver} 这个 key 整体复原成覆写前的原始数组、再 purge 掉
     * 缓存的 mailer 实例。
     *
     * 注意：复原时必须整体替换回原数组，不能逐个 key 单独设回 null——
     * MailManager::createSesTransport() 是把 mail.mailers.ses 数组 array_merge
     * 在 services.ses 平台默认凭证之上的，如果复原后 key/secret/region 变成
     * "存在但值为 null"，array_merge 依然会用这些 null 盖掉平台默认凭证，
     * 导致后续同一 worker 进程里所有走平台默认 SES 的邮件全部失败。
     *
     * @param  array{sender_email: string, sender_name: ?string, mail_driver: string, mail_credentials: array<string, mixed>}  $sender
     */
    private function sendWithSender(Order $order, array $sender): void
    {
        $driver = $sender['mail_driver'];
        $configKey = "mail.mailers.{$driver}";
        $original = config($configKey, []);
        $overrides = MailerCredentials::overridesFor($driver, $sender['mail_credentials']);

        try {
            config([$configKey => array_merge($original, $overrides)]);
            Mail::purge($driver);

            Mail::mailer($driver)->to($order->customer_email)->send(new PaymentLinkMail($order, $sender));
        } finally {
            config([$configKey => $original]);
            Mail::purge($driver);
        }
    }
}

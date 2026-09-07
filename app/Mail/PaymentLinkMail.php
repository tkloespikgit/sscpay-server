<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * 付款链接邮件（4.4 节）。发件人固定用调用方（SendPaymentLinkJob）通过
 * Order::resolveMailSender() 解析出来的那一份身份（锁定支付方式 or 所属
 * Application 二选一），不在这里自己再走一遍链路、也不回退平台默认发件人——
 * 平台没有自己的发信身份可用，这是业务铁律（3.x 节：商户必须用自己的 ESP
 * 账号发信）。调用方必须保证 $sender 非空——SendPaymentLinkJob 会在
 * resolveMailSender() 返回 null 时直接判定失败，不构造/不发送这个 Mailable。
 */
class PaymentLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{sender_email: string, sender_name: ?string, mail_driver: string, mail_credentials: array<string, mixed>}  $sender
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $sender,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->sender['sender_email'], $this->sender['sender_name'] ?: $this->sender['sender_email']),
            subject: __('admin.mail.payment_link.subject', [
                'merchant' => $this->order->merchant->name,
                'order_no' => $this->order->order_no,
            ]),
        );
    }

    /**
     * 锁定的支付方式或所属 Application 配置了自定义正文时优先使用（后台用富文本
     * 编辑器编辑，存的是 HTML；{customer_name}/{payment_link} 两个占位变量做
     * 字符串替换，替换发生在 sanitizeHtml() 之前，即使占位符出现在标签属性里
     * ——如 <a href="{payment_link}">——替换后的 URL 仍会被一并过滤校验），
     * 优先级见 Order::resolveMailTemplate()：支付方式 > Application，与发信
     * 身份的优先级一致但互相独立判定——内容和发信基础设施不绑定。两处都未配置则
     * 回退系统默认模板 emails.payment-link。两种模板的付款链接都用
     * order.pay_url（支付网关插件返回的真实收银台地址）；pay_url 为空时
     * （理论上不应发生——只有远程创建支付订单成功后才会 dispatch 本邮件）
     * 兜底回退到本地付款页 token 链接，避免发出一个完全打不开的链接。
     */
    public function content(): Content
    {
        $customerName = trim($this->order->customer_first_name.' '.$this->order->customer_last_name);
        $paymentUrl = $this->order->pay_url ?: url("/payment/{$this->order->payment_link_token}");
        $template = $this->order->resolveMailTemplate();

        if (filled($template)) {
            $body = strtr($template, [
                '{customer_name}' => $customerName,
                '{payment_link}' => $paymentUrl,
            ]);

            return new Content(
                view: 'emails.payment-link-custom',
                with: [
                    // 富文本编辑器存的是未经信任的原始 HTML（商户后台用户填写），
                    // 发信前必须过一遍 Filament 自带的 HTML 净化器，剥离脚本/危险
                    // 属性，只保留富文本编辑器工具栏本身会产出的安全标签。
                    'body' => Str::sanitizeHtml($body),
                ],
            );
        }

        return new Content(
            markdown: 'emails.payment-link',
            with: [
                'order' => $this->order,
                'customerName' => $customerName,
                'paymentUrl' => $paymentUrl,
                'expireDate' => $this->order->created_at
                    ->addDays((int) \App\Models\SystemConfig::get('payment_link.expire_days', 7))
                    ->toDateString(),
            ],
        );
    }
}

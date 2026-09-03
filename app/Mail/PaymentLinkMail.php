<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 付款链接邮件（4.4 节）。发件人动态取 order.application.sender_email，
 * 为空则回退系统默认 config('mail.from.address')（2.6 节铁律）。
 */
class PaymentLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        $application = $this->order->application;

        $fromAddress = $application?->sender_email ?: config('mail.from.address');
        $fromName = $application?->sender_name ?: config('mail.from.name');

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: __('admin.mail.payment_link.subject', [
                'merchant' => $this->order->merchant->name,
                'order_no' => $this->order->order_no,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-link',
            with: [
                'order' => $this->order,
                'customerName' => trim($this->order->customer_first_name.' '.$this->order->customer_last_name),
                'paymentUrl' => url("/payment/{$this->order->payment_link_token}"),
                'expireDate' => $this->order->created_at
                    ->addDays((int) \App\Models\SystemConfig::get('payment_link.expire_days', 7))
                    ->toDateString(),
            ],
        );
    }
}

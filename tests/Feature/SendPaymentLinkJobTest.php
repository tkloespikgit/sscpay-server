<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentLinkJob;
use App\Mail\PaymentLinkMail;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class SendPaymentLinkJobTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    /** @param array<string, mixed> $paymentMethodOverrides */
    #[DataProvider('paymentMethodMailSettings')]
    public function test_sends_using_the_application_identity_and_driver(array $paymentMethodOverrides): void
    {
        $this->freezeTime();
        $order = $this->mailOrder([], $paymentMethodOverrides);
        $order->update(['payment_link_mail_failed_reason' => 'Previous failure']);
        $originalMailer = ['transport' => 'postmark', 'token' => 'platform-token'];
        config(['mail.mailers.postmark' => $originalMailer]);
        Mail::fake();
        Http::preventStrayRequests();

        (new SendPaymentLinkJob($order->id))->handle();

        Mail::assertSent(PaymentLinkMail::class, fn (PaymentLinkMail $mail): bool => $mail->order->id === $order->id
            && $mail->hasTo('john@example.com')
            && $mail->usesMailer('postmark')
            && $mail->envelope()->from->address === 'billing@app.example.com'
            && $mail->envelope()->from->name === 'Application Billing'
            && $mail->sender['mail_credentials'] === ['token' => 'application-token']);
        Mail::assertSentCount(1);
        $this->assertSame(now()->toDateTimeString(), $order->refresh()->payment_link_sent_at?->toDateTimeString());
        $this->assertNull($order->payment_link_mail_failed_reason);
        $this->assertSame($originalMailer, config('mail.mailers.postmark'));
        Http::assertNothingSent();
    }

    public function test_application_smtp_ignores_the_platform_url_and_restores_the_platform_configuration(): void
    {
        $this->freezeTime();
        $order = $this->mailOrder([
            'mail_driver' => 'smtp',
            'mail_credentials' => [
                'host' => 'smtp.application.example.com',
                'port' => 587,
                'username' => 'application-user',
                'password' => 'application-password',
                'encryption' => 'tls',
            ],
            'payment_link_mail_template' => '<p>Pay here: {payment_link}</p>',
        ]);
        $originalMailer = [
            'transport' => 'smtp',
            'url' => 'smtp://platform-user:platform-password@smtp.platform.example.com:2525',
            'host' => 'smtp.platform.example.com',
            'port' => 2525,
            'username' => 'platform-user',
            'password' => 'platform-password',
            'scheme' => 'smtp',
        ];
        config(['mail.mailers.smtp' => $originalMailer]);
        $transportConfiguration = [];
        $transport = new ArrayTransport;
        Event::fake([MessageSent::class]);
        Http::preventStrayRequests();
        Mail::extend('smtp', function (array $configuration) use (&$transportConfiguration, $transport): ArrayTransport {
            $transportConfiguration = $configuration;

            return $transport;
        });

        (new SendPaymentLinkJob($order->id))->handle();

        $this->assertSame([
            'url' => null,
            'host' => 'smtp.application.example.com',
            'port' => 587,
            'username' => 'application-user',
            'password' => 'application-password',
        ], Arr::only($transportConfiguration, ['url', 'host', 'port', 'username', 'password']));
        $this->assertCount(1, $transport->messages());
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->message->getFrom()[0]->getAddress() === 'billing@app.example.com'
            && $event->message->getTo()[0]->getAddress() === 'john@example.com'
            && str_contains($event->message->getHtmlBody(), 'https://pay.example.com/customer-link'));
        $this->assertSame($originalMailer, config('mail.mailers.smtp'));
        $this->assertSame(now()->toDateTimeString(), $order->refresh()->payment_link_sent_at?->toDateTimeString());
        $this->assertNull($order->payment_link_mail_failed_reason);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $applicationOverrides */
    #[DataProvider('invalidApplicationMailSettings')]
    public function test_missing_application_mail_settings_do_not_fall_back_to_the_payment_method_or_platform(array $applicationOverrides): void
    {
        $order = $this->mailOrder($applicationOverrides);
        config([
            'mail.default' => 'postmark',
            'mail.from.address' => 'platform@example.com',
            'mail.mailers.postmark' => ['transport' => 'postmark', 'token' => 'platform-token'],
        ]);
        Mail::fake();
        Http::preventStrayRequests();

        (new SendPaymentLinkJob($order->id))->handle();

        Mail::assertNothingOutgoing();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_link_sent_at' => null,
            'payment_link_mail_failed_reason' => __('admin.mail_credentials.send_failed_no_sender'),
        ]);
        Http::assertNothingSent();
    }

    public function test_an_application_with_email_disabled_does_not_send_or_record_a_failure(): void
    {
        $order = $this->mailOrder(['is_order_email_enabled' => false]);
        Mail::fake();
        Http::preventStrayRequests();

        (new SendPaymentLinkJob($order->id))->handle();

        Mail::assertNothingOutgoing();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_link_sent_at' => null,
            'payment_link_mail_failed_reason' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_another_merchants_application_cannot_supply_the_sender(): void
    {
        $order = $this->mailOrder();
        $otherMerchant = Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other Tester',
            'contact_phone' => '654321',
            'contact_email' => 'other-merchant@example.com',
        ]);
        $otherApplication = Application::createWithCredentials([
            'merchant_id' => $otherMerchant->id,
            'name' => 'Other App',
            'website' => 'https://other.example.com',
            'is_order_email_enabled' => true,
            'sender_email' => 'billing@other.example.com',
            'mail_driver' => 'postmark',
            'mail_credentials' => ['token' => 'other-application-token'],
        ]);
        $order->update(['application_id' => $otherApplication->id]);
        Mail::fake();
        Http::preventStrayRequests();

        (new SendPaymentLinkJob($order->id))->handle();

        Mail::assertNothingOutgoing();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_link_sent_at' => null,
            'payment_link_mail_failed_reason' => __('admin.mail_credentials.send_failed_no_sender'),
        ]);
        Http::assertNothingSent();
    }

    public function test_a_deleted_application_does_not_fall_back_to_the_payment_method(): void
    {
        $order = $this->mailOrder();
        $this->application->delete();
        Mail::fake();
        Http::preventStrayRequests();

        (new SendPaymentLinkJob($order->id))->handle();

        Mail::assertNothingOutgoing();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_link_sent_at' => null,
            'payment_link_mail_failed_reason' => __('admin.mail_credentials.send_failed_no_sender'),
        ]);
        Http::assertNothingSent();
    }

    #[DataProvider('applicationMailTemplates')]
    public function test_the_email_body_uses_only_the_application_template_or_the_system_default(?string $applicationTemplate, string $expectedBody): void
    {
        app()->setLocale('en');
        Http::preventStrayRequests();
        $order = $this->mailOrder(['payment_link_mail_template' => $applicationTemplate], [
            'payment_link_mail_template' => '<p>Legacy channel payment link</p>',
        ]);

        $html = (new PaymentLinkMail($order, $order->resolveMailSender()))->render();

        $this->assertSame($applicationTemplate, $order->resolveMailTemplate());
        $this->assertStringContainsString($expectedBody, $html);
        $this->assertStringContainsString('John Doe', $html);
        $this->assertStringContainsString('https://pay.example.com/customer-link', $html);
        $this->assertStringNotContainsString('Legacy channel payment link', $html);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string|null, string}> */
    public static function applicationMailTemplates(): array
    {
        return [
            'application_template' => ['<p>Application payment instructions for {customer_name}: <a href="{payment_link}">Pay now</a></p>', 'Application payment instructions'],
            'system_default' => [null, 'Complete Payment'],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function paymentMethodMailSettings(): array
    {
        return [
            'legacy_channel_configuration_present' => [[]],
            'channel_configuration_absent' => [[
                'sender_email' => null,
                'sender_name' => null,
                'mail_driver' => null,
                'mail_credentials' => null,
            ]],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidApplicationMailSettings(): array
    {
        return [
            'sender_absent' => [['sender_email' => null]],
            'driver_absent' => [['mail_driver' => null]],
            'credentials_incomplete' => [['mail_credentials' => []]],
            'driver_unsupported' => [['mail_driver' => 'unsupported']],
        ];
    }

    /**
     * @param  array<string, mixed>  $applicationOverrides
     * @param  array<string, mixed>  $paymentMethodOverrides
     */
    private function mailOrder(array $applicationOverrides = [], array $paymentMethodOverrides = []): Order
    {
        $this->createMerchantAndApplication();
        $this->application->update(array_merge([
            'is_order_email_enabled' => true,
            'sender_email' => 'billing@app.example.com',
            'sender_name' => 'Application Billing',
            'mail_driver' => 'postmark',
            'mail_credentials' => ['token' => 'application-token'],
        ], $applicationOverrides));
        $paymentMethod = $this->makePaymentMethod('mail-test');
        $paymentMethod->forceFill(array_merge([
            'sender_email' => 'billing@channel.example.com',
            'sender_name' => 'Channel Billing',
            'mail_driver' => 'smtp',
            'mail_credentials' => ['host' => 'smtp.channel.example.com', 'port' => 587],
        ], $paymentMethodOverrides))->save();

        return $this->makeOrder('SSC-MAIL', 'M-MAIL', [
            'payment_method_id' => $paymentMethod->id,
            'payment_method' => $paymentMethod->method_code,
            'pay_url' => 'https://pay.example.com/customer-link',
            'send_mail' => true,
        ]);
    }
}

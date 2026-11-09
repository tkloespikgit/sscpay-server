<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Jobs\SendPaymentLinkJob;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderPaymentLinkMailActionTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Filament::setCurrentPanel('admin');
    }

    public function test_resend_button_follows_send_mail_and_queues_the_payment_link(): void
    {
        $order = $this->makeOrder('MAIL-YES', 'M-MAIL-YES', ['send_mail' => true]);
        Queue::fake([SendPaymentLinkJob::class]);

        $component = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);

        $this->assertResendButtonFollowsSendMailField($component->html());

        $component->callAction(TestAction::make('resendPaymentLinkMail')->schemaComponent(
            'send_mail',
            'infolist',
        ));

        Queue::assertPushed(SendPaymentLinkJob::class, function (SendPaymentLinkJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_resend_button_stays_hidden_when_the_order_did_not_request_email(): void
    {
        $order = $this->makeOrder('MAIL-NO', 'M-MAIL-NO', ['send_mail' => false]);

        $html = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->html();

        $this->assertStringContainsString(__('admin.order.fields.send_mail'), $html);
        $this->assertStringNotContainsString(__('admin.order.actions.resend_payment_link_mail'), $html);
    }

    public function test_resend_button_stays_hidden_when_the_order_has_no_customer_email(): void
    {
        $order = $this->makeOrder('MAIL-EMPTY', 'M-MAIL-EMPTY', [
            'send_mail' => true,
            'customer_email' => '',
        ]);

        $html = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->html();

        $this->assertStringContainsString(__('admin.order.fields.send_mail'), $html);
        $this->assertStringNotContainsString(__('admin.order.actions.resend_payment_link_mail'), $html);
    }

    private function assertResendButtonFollowsSendMailField(string $html): void
    {
        $field = __('admin.order.fields.send_mail');
        $button = __('admin.order.actions.resend_payment_link_mail');
        $nextField = __('admin.order.fields.payment_link_sent_at');
        $fieldPosition = strpos($html, $field);

        $this->assertNotFalse($fieldPosition);
        $this->assertStringContainsString('order-send-mail-entry', $html);

        $afterField = substr($html, $fieldPosition);
        $buttonPosition = strpos($afterField, $button);
        $nextFieldPosition = strpos($afterField, $nextField);

        $this->assertNotFalse($buttonPosition);
        $this->assertNotFalse($nextFieldPosition);
        $this->assertLessThan($nextFieldPosition, $buttonPosition);
        $this->assertSame(1, substr_count($html, $button));
    }
}

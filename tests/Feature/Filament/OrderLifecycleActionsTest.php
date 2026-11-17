<?php

namespace Tests\Feature\Filament;

use App\Events\OrderStatusChanged;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderPaymentStatusService;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderLifecycleActionsTest extends TestCase
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

    #[TestWith(['pending_settlement'])]
    #[TestWith(['completed'])]
    public function test_paid_orders_allow_financial_actions_and_cannot_be_manually_marked_complete(string $status): void
    {
        $order = $this->makeOrder('ACTIONS-'.$status, 'M-'.$status, ['status' => $status, 'paid_at' => now()]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('refund')
            ->assertActionVisible('chargeback')
            ->assertActionVisible('openDisputeEvent')
            ->assertActionHidden('manualStatusChange');
    }

    public function test_pending_order_can_only_be_manually_marked_failed(): void
    {
        $order = $this->makeOrder('MANUAL-FAIL', 'M-MANUAL-FAIL');
        Event::fake([OrderStatusChanged::class]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('manualStatusChange', ['target_status' => 'completed'])
            ->assertHasFormErrors(['target_status']);
        $this->assertSame('pending', $order->refresh()->status);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('manualStatusChange', ['target_status' => 'failed', 'reason' => 'Confirmed unpaid'])
            ->assertHasNoFormErrors();

        $this->assertSame('failed', $order->refresh()->status);
        $this->assertNotNull($order->failed_at);
        $this->assertStringContainsString('Confirmed unpaid', $order->remark);
    }

    public function test_refund_permission_is_rechecked_after_the_modal_is_opened(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_REFUND]);
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $order = $this->makeOrder('REVOKED-REFUND', 'M-REVOKED-REFUND', ['status' => 'completed']);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('refund')
            ->fillForm(['amount' => '10.00', 'mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret())]);
        $user->revokePermissionTo(Permissions::ORDERS_REFUND);

        $page->call('callMountedAction')->assertSuccessful();

        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertSame('completed', $order->refresh()->status);
    }

    public function test_disabled_user_cannot_execute_an_already_open_refund(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_REFUND]);
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $order = $this->makeOrder('DISABLED-REFUND', 'M-DISABLED-REFUND', ['status' => 'completed']);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('refund')
            ->fillForm(['amount' => '10.00', 'mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret())]);
        $user->update(['status' => false]);

        $page->call('callMountedAction')->assertSuccessful();

        $this->assertDatabaseCount('order_refunds', 0);
    }

    #[TestWith(['zh_CN'])]
    #[TestWith(['en'])]
    public function test_a_merchant_can_convert_a_fully_refunded_order_with_the_snapshotted_fee_and_totp(string $locale): void
    {
        app()->setLocale($locale);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_CHARGEBACK]);
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $order = $this->refundedOrder($user);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('chargeback')
            ->assertActionHasLabel('chargeback', __('admin.finance.chargeback.refund_conversion_action'))
            ->assertActionExists('chargeback', fn (Action $action): bool => $action->getModalDescription()
                === __('admin.finance.chargeback.refund_conversion_desc', ['fee' => '12.00']))
            ->mountAction('chargeback')
            ->assertActionMounted('chargeback')
            ->fillForm([
                'reason' => '拒付拦截补扣手续费',
                'mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret()),
            ])
            ->call('callMountedAction')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.finance.chargeback.success'))
            ->assertActionHidden('chargeback');

        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame('886.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'merchant_id' => $this->merchant->id, 'order_id' => $order->id,
            'type' => 'chargeback_fee', 'amount' => '-12.00',
            'operator_id' => $user->id, 'reason' => '拒付拦截补扣手续费',
        ]);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_refund_to_chargeback_conversion_requires_a_bound_authenticator(): void
    {
        $user = auth()->user();
        $order = $this->refundedOrder($user);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('chargeback', ['reason' => '拒付拦截', 'mfa_code' => '123456'])
            ->assertNotified(__('admin.finance.security.not_bound'));

        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('898.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseMissing('merchant_balance_transactions', ['type' => 'chargeback']);
    }

    public function test_chargeback_permission_is_rechecked_after_opening_a_refund_conversion(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_CHARGEBACK]);
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $order = $this->refundedOrder($user);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('chargeback')
            ->fillForm(['mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret())]);
        $user->revokePermissionTo(Permissions::ORDERS_CHARGEBACK);

        $page->call('callMountedAction')->assertSuccessful();

        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('898.00', $this->merchant->refresh()->balance);
        $this->assertDatabaseMissing('merchant_balance_transactions', ['type' => 'chargeback_fee']);
    }

    #[TestWith(['refunded', '0.00'])]
    #[TestWith(['refunded', '50.00'])]
    #[TestWith(['partially_refunded', '150.00'])]
    public function test_incomplete_refunds_do_not_offer_chargeback_conversion(string $status, string $refundedAmount): void
    {
        $order = $this->makeOrder('INCOMPLETE-REFUND', 'M-INCOMPLETE-REFUND', [
            'status' => $status, 'refunded_amount' => $refundedAmount,
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('chargeback');
    }

    #[TestWith(['zh_CN'])]
    #[TestWith(['en'])]
    public function test_partial_refund_conversion_shows_remaining_principal_and_requires_a_reason(string $locale): void
    {
        app()->setLocale($locale);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo([Permissions::ORDERS_VIEW, Permissions::ORDERS_CHARGEBACK]);
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $order = $this->refundedOrder($user, '30.00');
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('chargeback')
            ->assertActionHasLabel('chargeback', __('admin.finance.chargeback.partial_refund_conversion_action'))
            ->assertActionExists('chargeback', fn (Action $action): bool => $action->getModalDescription()
                === __('admin.finance.chargeback.partial_refund_conversion_desc', [
                    'refunded' => '30.00', 'amount' => '70.00', 'fee' => '12.00', 'total' => '82.00',
                ]))
            ->mountAction('chargeback')
            ->fillForm(['mfa_code' => app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret())])
            ->call('callMountedAction')
            ->assertHasFormErrors(['reason' => 'required']);
        $this->assertSame('968.00', $this->merchant->refresh()->balance);

        $page->fillForm(['reason' => '核对后确认剩余本金拒付'])
            ->call('callMountedAction')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.finance.chargeback.success'))
            ->assertActionHidden('chargeback');

        $this->assertSame('886.00', $this->merchant->refresh()->balance);
        $this->assertSame('chargeback', $order->refresh()->status);
        $this->assertSame('30.00', $order->refunded_amount);
        $this->assertDatabaseHas('merchant_balance_transactions', [
            'order_id' => $order->id, 'type' => 'chargeback', 'amount' => '-70.00',
        ]);
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_actual_settlement_filter_is_independent_of_order_status_and_reversal_accounting(): void
    {
        $settled = $this->makeOrder('SETTLED-PARTIAL', 'M-SETTLED-PARTIAL', ['status' => 'partially_refunded']);
        $pending = $this->makeOrder('MANUAL-COMPLETED', 'M-MANUAL-COMPLETED', ['status' => 'completed']);
        $legacy = $this->makeOrder('LEGACY-COMPLETED', 'M-LEGACY-COMPLETED', ['status' => 'completed']);
        $this->settlement($settled, 'settled');
        $this->settlement($pending, 'pending');

        Livewire::test(ListOrders::class)
            ->filterTable('settlement_status', 'settled')
            ->assertCanSeeTableRecords([$settled])
            ->assertCanNotSeeTableRecords([$pending, $legacy])
            ->assertTableColumnFormattedStateSet('settlement.status', __('settlement.statuses.settled'), $settled)
            ->filterTable('settlement_status', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$settled, $legacy])
            ->filterTable('settlement_status', 'none')
            ->assertCanSeeTableRecords([$legacy])
            ->assertCanNotSeeTableRecords([$settled, $pending]);
    }

    public function test_query_action_cannot_run_after_order_view_permission_is_revoked(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        $order = $this->makeOrder('REVOKED-QUERY', 'M-REVOKED-QUERY', ['status' => 'completed']);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);
        $this->mock(OrderPaymentStatusService::class)->shouldNotReceive('queryAndApply');
        $user->revokePermissionTo(Permissions::ORDERS_VIEW);

        $page->call('mountAction', 'queryOrderStatus');

        $this->assertSame('completed', $order->refresh()->status);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
    }

    #[TestWith(['pending_settlement', 'pending'])]
    #[TestWith(['completed', 'settled'])]
    public function test_payment_based_order_can_record_shipping_without_losing_settlement_progress(string $status, string $settlementStatus): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $order = $this->makeOrder('SETTLEMENT-SHIP', 'M-SETTLEMENT-SHIP', [
            'status' => $status, 'paid_at' => now(), 'settlement_mode' => 'immediate', 'settlement_days' => 7,
        ]);
        $this->settlement($order, $settlementStatus);
        Carrier::create(['carrier_code' => 'ups', 'carrier_name' => 'UPS', 'status' => Carrier::STATUS_ENABLED]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction(TestAction::make('recordShipment')->schemaComponent(), [
                'logistics_company' => 'ups',
                'tracking_number' => '1Z-COMPLETED',
                'shipped_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('order_shippings', ['order_id' => $order->id, 'tracking_number' => '1Z-COMPLETED']);
        $this->assertSame($status, $order->refresh()->status);
        Http::assertNothingSent();
    }

    private function refundedOrder(User $operator, string $refundAmount = '100.00'): Order
    {
        $this->merchant->update(['balance' => '1000.00']);
        $method = $this->makePaymentMethod('refund-conversion');
        $method->update(['chargeback_fee' => '50.00']);
        $order = $this->makeOrder('REFUND-CONVERSION', 'M-REFUND-CONVERSION', [
            'status' => 'paid', 'paid_at' => now(), 'payment_method_id' => $method->id,
            'payment_method' => $method->method_code, 'refund_fee_snapshot' => '2.00',
            'chargeback_fee_snapshot' => '12.00',
        ]);
        app(BalanceService::class)->refund($order, $refundAmount, $operator);

        return $order->refresh();
    }

    private function settlement(Order $order, string $status): OrderSettlement
    {
        return OrderSettlement::create([
            'merchant_id' => $order->merchant_id, 'order_id' => $order->id,
            'amount' => '95.00', 'remaining_amount' => $status === 'settled' ? '0.00' : '95.00',
            'mode' => 'immediate', 'cycle_days' => 7, 'reserve_percent' => '0', 'reserve_days' => 0,
            'status' => $status, 'started_at' => now()->subDays(7), 'due_at' => $status === 'settled' ? now() : now()->addDays(7),
            'settled_at' => $status === 'settled' ? now() : null,
        ]);
    }
}

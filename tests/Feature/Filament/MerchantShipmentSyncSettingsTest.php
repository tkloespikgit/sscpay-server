<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\RelationManagers\OrderShipmentSyncsRelationManager;
use App\Jobs\SyncMerchantShipmentJob;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderShipmentSync;
use App\Models\OrderShipping;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class MerchantShipmentSyncSettingsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_application_administrator_can_enable_sync_with_a_complete_https_url_without_backfilling_shipments(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->createMerchantAndApplication();
        $this->createShipment('HISTORICAL');
        $administrator = $this->applicationAdministrator();
        $url = 'https://merchant.example.com/custom/shipment?source=sscpay&store=5';

        Livewire::actingAs($administrator)
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->fillForm([
                'is_shipment_sync_enabled' => true,
                'shipment_sync_url' => $url,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertTrue($this->application->fresh()->is_shipment_sync_enabled);
        $this->assertSame($url, $this->application->fresh()->shipment_sync_url);
        $this->assertDatabaseCount('order_shipment_syncs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_enabling_shipment_sync_requires_an_endpoint(): void
    {
        Queue::fake();
        $this->createMerchantAndApplication();

        Livewire::actingAs($this->applicationAdministrator())
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->fillForm(['is_shipment_sync_enabled' => true, 'shipment_sync_url' => null])
            ->call('save')
            ->assertHasFormErrors(['shipment_sync_url' => 'required'])
            ->assertNotNotified();

        $this->assertFalse($this->application->fresh()->is_shipment_sync_enabled);
        Queue::assertNothingPushed();
    }

    public function test_application_form_rejects_an_http_endpoint(): void
    {
        Queue::fake();
        $this->createMerchantAndApplication();

        Livewire::actingAs($this->applicationAdministrator())
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->fillForm([
                'is_shipment_sync_enabled' => true,
                'shipment_sync_url' => 'http://merchant.example.com/api/fulfillment/shipment',
            ])
            ->call('save')
            ->assertHasFormErrors(['shipment_sync_url'])
            ->assertNotNotified();

        $this->assertFalse($this->application->fresh()->is_shipment_sync_enabled);
        Queue::assertNothingPushed();
    }

    public function test_shipment_sync_can_remain_disabled_without_an_endpoint(): void
    {
        Queue::fake();
        $this->createMerchantAndApplication();

        Livewire::actingAs($this->applicationAdministrator())
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->fillForm(['is_shipment_sync_enabled' => false, 'shipment_sync_url' => null])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertFalse($this->application->fresh()->is_shipment_sync_enabled);
        $this->assertNull($this->application->fresh()->shipment_sync_url);
        Queue::assertNothingPushed();
    }

    public function test_application_settings_cannot_be_edited_by_another_merchants_administrator(): void
    {
        Queue::fake();
        $this->createMerchantAndApplication();
        $otherAdministrator = $this->applicationAdministrator();
        $otherAdministrator->merchant_id = $this->otherMerchant()->id;
        $otherAdministrator->save();

        Livewire::actingAs($otherAdministrator)
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->assertNotFound();

        $this->assertFalse($this->application->fresh()->is_shipment_sync_enabled);
        Queue::assertNothingPushed();
    }

    public function test_order_shipper_can_manually_push_historical_logistics_without_changing_the_shipment(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->createMerchantAndApplication();
        $order = $this->createShipment('HISTORICAL');
        $before = $order->shipping->getRawOriginal();
        $this->enableSync();

        Livewire::actingAs($this->orderUser(true))
            ->test(ViewOrder::class, ['record' => $order->id])
            ->assertActionVisible(TestAction::make('pushMerchantShipment')->schemaComponent())
            ->callAction(TestAction::make('pushMerchantShipment')->schemaComponent())
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin.merchant_shipment_sync.queued'));

        $sync = OrderShipmentSync::query()->sole();
        $this->assertSame($order->id, $sync->order_id);
        $this->assertSame($this->merchant->id, $sync->merchant_id);
        $this->assertSame('HISTORICAL', $sync->payload['tracking_number']);
        $this->assertSame($order->merchant_order_no, $sync->payload['merchant_order_no']);
        $this->assertSame($before, $order->shipping->fresh()->getRawOriginal());
        $this->assertDatabaseCount('order_shippings', 1);
        Queue::assertPushed(SyncMerchantShipmentJob::class, fn (SyncMerchantShipmentJob $job): bool => $job->syncId === $sync->id && $job->merchantId === $this->merchant->id);
        Http::assertNothingSent();
    }

    public function test_read_only_order_user_cannot_push_a_shipment(): void
    {
        Queue::fake();
        $this->createMerchantAndApplication();
        $order = $this->createShipment('READ-ONLY');
        $this->enableSync();

        Livewire::actingAs($this->orderUser(false))
            ->test(ViewOrder::class, ['record' => $order->id])
            ->assertDontSee(__('admin.merchant_shipment_sync.push'))
            ->assertNotNotified();

        $this->assertDatabaseCount('order_shipment_syncs', 0);
        Queue::assertNothingPushed();
    }

    public function test_order_details_show_shipment_sync_attempts_and_escape_remote_responses(): void
    {
        Queue::fake();
        $this->createMerchantAndApplication();
        $order = $this->createShipment('HISTORY');
        $sync = OrderShipmentSync::create([
            'merchant_id' => $this->merchant->id,
            'order_id' => $order->id,
            'application_id' => $this->application->id,
            'order_shipping_id' => $order->shipping->id,
            'payload' => ['tracking_number' => 'ORIGINAL-HISTORY'],
            'status' => 'failed',
            'attempt_count' => 1,
            'last_error' => 'Merchant order not found',
            'alert_status' => 'unavailable',
        ]);
        $sync->attempts()->create([
            'merchant_id' => $this->merchant->id,
            'attempt_number' => 1,
            'request_url' => 'https://merchant.example.com/api/fulfillment/shipment?store=5',
            'response_status' => 404,
            'response_body' => '<script>alert("history-response")</script>',
            'error_message' => 'Merchant order not found',
            'attempted_at' => now(),
        ]);

        Livewire::actingAs($this->orderUser(false))
            ->test(ViewOrder::class, ['record' => $order->id])
            ->assertOk()
            ->assertSee(__('admin.merchant_shipment_sync.tab'))
            ->assertSee('Merchant order not found')
            ->assertDontSee('ORIGINAL-HISTORY')
            ->assertDontSeeHtml('<script>alert("history-response")</script>');

        Livewire::actingAs($this->orderUser(false))
            ->test(OrderShipmentSyncsRelationManager::class, [
                'ownerRecord' => $order,
                'pageClass' => ViewOrder::class,
            ])
            ->assertOk()
            ->assertSee('ORIGINAL-HISTORY')
            ->assertSee(__('admin.merchant_shipment_sync.alert_statuses.unavailable'))
            ->assertSee('https://merchant.example.com/api/fulfillment/shipment?store=5')
            ->assertSee('history-response')
            ->assertDontSeeHtml('<script>alert("history-response")</script>');

        Queue::assertNothingPushed();
    }

    public function test_disabling_application_sync_hides_manual_push_even_for_order_shippers(): void
    {
        Queue::fake();
        $this->createMerchantAndApplication();
        $order = $this->createShipment('DISABLED');

        Livewire::actingAs($this->orderUser(true))
            ->test(ViewOrder::class, ['record' => $order->id])
            ->assertDontSee(__('admin.merchant_shipment_sync.push'))
            ->assertNotNotified();

        $this->assertDatabaseCount('order_shipment_syncs', 0);
        Queue::assertNothingPushed();
    }

    public function test_order_shipper_cannot_view_or_push_another_merchants_order(): void
    {
        Queue::fake();
        $this->createMerchantAndApplication();
        $order = $this->createShipment('OTHER-MERCHANT');
        $this->enableSync();
        $otherUser = $this->orderUser(true);
        $otherUser->merchant_id = $this->otherMerchant()->id;
        $otherUser->save();

        Livewire::actingAs($otherUser)
            ->test(ViewOrder::class, ['record' => $order->id])
            ->assertNotFound();

        $this->assertDatabaseCount('order_shipment_syncs', 0);
        Queue::assertNothingPushed();
    }

    private function applicationAdministrator(): User
    {
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::APPLICATIONS_MANAGE);

        return $user;
    }

    private function orderUser(bool $canShip): User
    {
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::ORDERS_VIEW);

        if ($canShip) {
            $user->givePermissionTo(Permissions::ORDERS_SHIP);
        }

        return $user;
    }

    private function enableSync(): void
    {
        $this->application->update([
            'is_shipment_sync_enabled' => true,
            'shipment_sync_url' => 'https://merchant.example.com/api/fulfillment/shipment?store=5',
        ]);
    }

    private function otherMerchant(): Merchant
    {
        return Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other Contact',
            'contact_phone' => '123456',
            'contact_email' => 'other-merchant@example.com',
        ]);
    }

    private function createShipment(string $trackingNumber): Order
    {
        $order = $this->makeOrder('SSC-'.$trackingNumber, 'M-'.$trackingNumber, ['status' => 'shipped']);
        $order->shipping()->create([
            'merchant_id' => $this->merchant->id,
            'logistics_company' => 'ups',
            'tracking_number' => $trackingNumber,
            'shipped_at' => now()->subDays(5),
            'operator_id' => 0,
            'sync_status' => OrderShipping::SYNC_STATUS_SYNCED,
        ]);

        return $order;
    }
}

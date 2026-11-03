<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApplicationResource\Pages\CreateApplication;
use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Permissions;
use App\Support\ShipmentSyncEndpoint;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ShipmentSyncProbeActionTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const URL = 'https://probe.example.com/custom/shipment?source=sscpay&store=main';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_an_administrator_can_probe_an_unsaved_url_without_changing_the_application_or_pushing_logistics(): void
    {
        $this->createMerchantAndApplication();
        $this->application->update(['shipment_sync_url' => 'https://saved.example.com/previous']);
        $before = $this->application->fresh()->getRawOriginal();
        $this->resolvePublicEndpoint();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response('', 200)]);

        Livewire::actingAs($this->administrator())
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->fillForm(['shipment_sync_url' => self::URL])
            ->assertActionVisible($this->probeAction())
            ->callAction($this->probeAction())
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin.application.shipment_probe.titles.reachable'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD'
            && $request->url() === self::URL && $request->body() === ''
            && $request->header('App-ID') === [] && $request->header('Authorization') === []);
        Http::assertSentCount(1);
        $this->assertSame($before, $this->application->fresh()->getRawOriginal());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_shippings', 0);
        $this->assertDatabaseCount('order_shipment_syncs', 0);
        Queue::assertNothingPushed();
    }

    public function test_the_create_form_can_probe_before_required_application_fields_are_filled_without_creating_an_application(): void
    {
        $this->createMerchantAndApplication();
        $this->resolvePublicEndpoint();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response('', 405)]);

        Livewire::actingAs($this->administrator())
            ->test(CreateApplication::class)
            ->fillForm(['shipment_sync_url' => self::URL])
            ->assertActionVisible($this->probeAction())
            ->callAction($this->probeAction())
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin.application.shipment_probe.titles.requires_post'));

        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_shipment_syncs', 0);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD' && $request->url() === self::URL);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    #[DataProvider('invalidAddresses')]
    public function test_invalid_addresses_show_a_failure_notification_without_saving_or_sending_a_request(string $url): void
    {
        $this->createMerchantAndApplication();
        Queue::fake();
        Http::preventStrayRequests();

        Livewire::actingAs($this->administrator())
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->fillForm(['shipment_sync_url' => $url])
            ->callAction($this->probeAction())
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin.application.shipment_probe.titles.invalid_address'));

        $this->assertNull($this->application->fresh()->shipment_sync_url);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{string}> */
    public static function invalidAddresses(): array
    {
        return [
            'empty address' => [''],
            'unencrypted address' => ['http://probe.example.com/shipment'],
            'private address' => ['https://127.0.0.1/shipment'],
        ];
    }

    public function test_a_user_without_application_management_permission_cannot_access_the_probe(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        Queue::fake();
        Http::preventStrayRequests();

        Livewire::actingAs($user)
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->assertForbidden();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_an_inactive_application_administrator_cannot_mount_the_probe_action(): void
    {
        $this->createMerchantAndApplication();
        $user = $this->administrator();
        $user->update(['status' => false]);
        Queue::fake();
        Http::preventStrayRequests();

        Livewire::actingAs($user)
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->assertActionHidden($this->probeAction())
            ->mountAction($this->probeAction())
            ->assertNotNotified();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_an_administrator_cannot_probe_from_another_merchants_application(): void
    {
        $this->createMerchantAndApplication();
        $otherMerchant = Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other Operator',
            'contact_phone' => '987654',
            'contact_email' => 'other@example.com',
        ]);
        $otherAdministrator = $this->administrator();
        $otherAdministrator->update(['merchant_id' => $otherMerchant->id]);
        Queue::fake();
        Http::preventStrayRequests();

        Livewire::actingAs($otherAdministrator)
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->assertNotFound();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function resolvePublicEndpoint(): void
    {
        $this->partialMock(ShipmentSyncEndpoint::class)
            ->shouldReceive('resolveHost')
            ->once()
            ->with('probe.example.com')
            ->andReturn(['93.184.216.34']);
    }

    private function administrator(): User
    {
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::APPLICATIONS_MANAGE);

        return $user;
    }

    private function probeAction(): TestAction
    {
        return TestAction::make('probeShipmentSyncUrl')->schemaComponent('shipment_sync_url');
    }
}

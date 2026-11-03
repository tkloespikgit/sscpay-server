<?php

namespace Tests\Feature;

use App\Filament\Pages\OnboardMerchant;
use App\Filament\Resources\MerchantResource;
use App\Filament\Resources\MerchantResource\Pages\CreateMerchant;
use App\Filament\Resources\MerchantResource\Pages\EditMerchant;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\MerchantPaymentSetting;
use App\Models\User;
use App\Services\MerchantRoleProvisioningService;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class MerchantCreationDefaultsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_super_administrator_can_create_a_merchant_without_contact_information(): void
    {
        $administrator = $this->administrator(true);
        $owner = $this->administrator();
        $this->actingAs($administrator);
        Http::preventStrayRequests();

        Livewire::test(CreateMerchant::class)
            ->fillForm([
                'name' => 'Merchant Created by Super Administrator',
                'owner_id' => $owner->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $merchant = Merchant::query()->sole();
        $this->assertSame($owner->id, $merchant->owner_id);
        $this->assertSame('Merchant Created by Super Administrator', $merchant->name);
        $this->assertEmptyContactInformation($merchant);
        $this->assertSame([
            'default_fee_percent' => '0.0000',
            'default_fee_fixed' => '0.00',
            'default_refund_fee' => '0.00',
            'default_chargeback_fee' => '0.00',
            'default_reserve_percent' => '0.0000',
            'default_reserve_days' => 0,
            'settlement_mode' => 'immediate',
            'settlement_days' => 0,
        ], $merchant->only([
            'default_fee_percent', 'default_fee_fixed', 'default_refund_fee', 'default_chargeback_fee',
            'default_reserve_percent', 'default_reserve_days', 'settlement_mode', 'settlement_days',
        ]));
        Http::assertNothingSent();
    }

    public function test_manager_can_create_an_owned_merchant_without_contact_information(): void
    {
        $manager = $this->administrator();
        $this->actingAs($manager);
        Http::preventStrayRequests();

        Livewire::test(CreateMerchant::class)
            ->fillForm(['name' => 'Merchant Created by Manager'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $merchant = Merchant::query()->sole();
        $this->assertSame($manager->id, $merchant->owner_id);
        $this->assertSame('Merchant Created by Manager', $merchant->name);
        $this->assertEmptyContactInformation($merchant);
        Http::assertNothingSent();
    }

    public function test_onboarding_creates_the_merchant_account_and_application_without_contact_information(): void
    {
        $manager = $this->administrator();
        $this->actingAs($manager);
        Http::preventStrayRequests();

        $page = Livewire::test(OnboardMerchant::class)
            ->fillForm([
                'admin_name' => 'New Merchant Administrator',
                'admin_account' => 'new-merchant-admin',
                'admin_password' => 'merchant-test-password',
                'app_name' => 'New Merchant Application',
                'app_website' => 'https://shop.example.com',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.onboard_merchant.notifications.created'));

        $merchant = Merchant::query()->sole();
        $this->assertSame($manager->id, $merchant->owner_id);
        $this->assertEmptyContactInformation($merchant);
        $page->assertRedirect(MerchantResource::getUrl('edit', ['record' => $merchant]));

        $admin = User::query()->where('merchant_id', $merchant->id)->sole();
        $this->assertSame('new-merchant-admin@test.example.com', $admin->email);
        $this->assertTrue(Hash::check('merchant-test-password', $admin->password));
        $this->assertTrue($admin->hasRole(MerchantRoleProvisioningService::MERCHANT_ADMIN_LABEL));

        $application = Application::query()->sole();
        $this->assertSame($merchant->id, $application->merchant_id);
        $this->assertSame('New Merchant Application', $application->name);
        $this->assertSame('https://shop.example.com', $application->website);
        $this->assertNotEmpty($application->app_id);
        $this->assertNotEmpty($application->api_key);
        Http::assertNothingSent();
    }

    public function test_editing_a_historical_merchant_preserves_existing_contact_information(): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->administrator();
        $this->merchant->update(['owner_id' => $manager->id]);
        $this->actingAs($manager);
        Http::preventStrayRequests();

        Livewire::test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->fillForm(['name' => 'Updated Historical Merchant'])
            ->call('save')
            ->assertHasNoFormErrors();

        $merchant = $this->merchant->fresh();
        $this->assertSame('Updated Historical Merchant', $merchant->name);
        $this->assertSame('Tester', $merchant->contact_person);
        $this->assertSame('123456', $merchant->contact_phone);
        $this->assertSame('merchant@example.com', $merchant->contact_email);
        Http::assertNothingSent();
    }

    public function test_creating_a_merchant_saves_its_default_financial_and_settlement_terms(): void
    {
        $manager = $this->administrator();
        $this->actingAs($manager);
        Http::preventStrayRequests();
        $defaults = $this->financialDefaults();

        Livewire::test(CreateMerchant::class)
            ->fillForm(['name' => 'Merchant With Financial Defaults', ...$defaults])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $merchant = Merchant::query()->sole();
        $this->assertSame($defaults, $merchant->only(array_keys($defaults)));
        $this->assertSame($manager->id, $merchant->owner_id);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
        Http::assertNothingSent();
    }

    public function test_onboarding_saves_the_same_default_financial_and_settlement_terms(): void
    {
        $this->actingAs($this->administrator());
        Http::preventStrayRequests();
        $defaults = $this->financialDefaults();

        Livewire::test(OnboardMerchant::class)
            ->fillForm([
                'admin_name' => 'Administrator With Financial Defaults',
                'admin_account' => 'financial-default-admin',
                'admin_password' => 'merchant-test-password',
                'app_name' => 'Financial Defaults Application',
                'app_website' => 'https://shop.example.com',
                ...$defaults,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.onboard_merchant.notifications.created'));

        $merchant = Merchant::query()->sole();
        $this->assertSame($defaults, $merchant->only(array_keys($defaults)));
        $this->assertDatabaseCount('merchant_payment_settings', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['default_fee_percent', '100.0000'])]
    #[TestWith(['default_fee_percent', '2.12345'])]
    #[TestWith(['default_fee_fixed', '-0.01'])]
    #[TestWith(['default_refund_fee', '1.001'])]
    #[TestWith(['default_chargeback_fee', '10000000000000.00'])]
    #[TestWith(['default_reserve_percent', '100.0001'])]
    #[TestWith(['default_reserve_days', '36501'])]
    #[TestWith(['default_reserve_days', '1.5'])]
    #[TestWith(['default_fee_percent', null])]
    public function test_invalid_default_financial_terms_do_not_create_a_merchant(string $field, ?string $value): void
    {
        $this->actingAs($this->administrator());
        Http::preventStrayRequests();

        Livewire::test(CreateMerchant::class)
            ->fillForm(['name' => 'Invalid Financial Defaults', $field => $value])
            ->call('create')
            ->assertHasFormErrors([$field]);

        $this->assertDatabaseCount('merchants', 0);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
        Http::assertNothingSent();
    }

    public function test_editing_merchant_defaults_preserves_individual_payment_method_settings(): void
    {
        $this->createMerchantAndApplication();
        $manager = $this->administrator();
        $this->merchant->update(['owner_id' => $manager->id]);
        $method = $this->makePaymentMethod('individual_terms');
        $individualTerms = [
            'fee_percent' => '4.2500',
            'fee_fixed' => '0.50',
            'refund_fee' => '3.00',
            'chargeback_fee' => '20.00',
            'reserve_percent' => '15.0000',
            'reserve_days' => 120,
            'settlement_mode' => 'immediate',
            'settlement_days' => 2,
        ];
        $setting = MerchantPaymentSetting::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $method->id,
            ...$individualTerms,
        ]);
        $this->actingAs($manager);
        Http::preventStrayRequests();
        $defaults = $this->financialDefaults();

        Livewire::test(EditMerchant::class, ['record' => $this->merchant->getRouteKey()])
            ->fillForm($defaults)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($defaults, $this->merchant->fresh()->only(array_keys($defaults)));
        $this->assertSame($individualTerms, $setting->fresh()->only(array_keys($individualTerms)));
        $this->assertDatabaseCount('merchant_payment_settings', 1);
        Http::assertNothingSent();
    }

    /** @return array<string, int|string> */
    private function financialDefaults(): array
    {
        return [
            'default_fee_percent' => '2.1250',
            'default_fee_fixed' => '0.30',
            'default_refund_fee' => '5.00',
            'default_chargeback_fee' => '15.00',
            'default_reserve_percent' => '10.0000',
            'default_reserve_days' => 90,
            'settlement_mode' => 'shipped',
            'settlement_days' => 7,
        ];
    }

    private function administrator(bool $isSuperAdmin = false): User
    {
        $user = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => $isSuperAdmin,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::MERCHANTS_MANAGE);

        return $user;
    }

    private function assertEmptyContactInformation(Merchant $merchant): void
    {
        $this->assertSame('', $merchant->contact_person);
        $this->assertSame('', $merchant->contact_phone);
        $this->assertSame('', $merchant->contact_email);
    }
}

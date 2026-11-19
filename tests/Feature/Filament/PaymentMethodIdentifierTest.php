<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentMethodResource\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentMethodIdentifierTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    #[TestWith(['PayPal Credit Card', 'paypal_credit_card'])]
    #[TestWith(['PayPal / Stripe 2026', 'paypal_stripe_2026'])]
    #[TestWith(['信用卡', 'xin_yong_ka'])]
    #[TestWith(['Café Pay', 'cafe_pay'])]
    #[TestWith(['123 !', '123'])]
    public function test_new_name_generates_an_identifier_containing_lowercase_letters_digits_and_underscores(string $name, string $identifier): void
    {
        $this->arrangeAdministrator();

        Livewire::test(CreatePaymentMethod::class)->set('data.method_name', $name)
            ->assertSet('data.method_code', $identifier);
    }

    public function test_generated_identifier_tracks_name_changes_and_clears_with_the_name(): void
    {
        $this->arrangeAdministrator();

        Livewire::test(CreatePaymentMethod::class)
            ->set('data.method_name', 'First Name')->assertSet('data.method_code', 'first_name')
            ->set('data.method_name', 'Next Name')->assertSet('data.method_code', 'next_name')
            ->set('data.method_name', '')->assertSet('data.method_code', '');
    }

    public function test_manual_identifier_is_preserved_when_the_name_changes(): void
    {
        $this->arrangeAdministrator();

        Livewire::test(CreatePaymentMethod::class)
            ->set('data.method_name', 'First Name')
            ->set('data.method_code', 'Custom_Route_2026')
            ->set('data.method_name', 'Next Name')->assertSet('data.method_code', 'Custom_Route_2026')
            ->set('data.method_code', '')
            ->set('data.method_name', 'Final Name')->assertSet('data.method_code', 'final_name');
    }

    public function test_transliterated_identifier_is_limited_to_fifty_characters(): void
    {
        $this->arrangeAdministrator();

        $page = Livewire::test(CreatePaymentMethod::class)->set('data.method_name', str_repeat('界', 20));

        $this->assertSame(50, strlen($page->get('data.method_code')));
        $this->assertMatchesRegularExpression('/\A[a-z0-9_]+\z/', $page->get('data.method_code'));
    }

    #[TestWith(['custom_route'])]
    #[TestWith(['PayPal_2026'])]
    #[TestWith(['credit1'])]
    #[TestWith(['123'])]
    public function test_manual_identifier_preserves_letters_digits_and_case_when_saved(string $identifier): void
    {
        $this->arrangeAdministrator();
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm([
            ...$this->creationData($identifier), 'display_name' => 'Customer label',
        ])->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseHas('payment_methods', [
            'method_code' => $identifier, 'method_name' => 'Card Route', 'display_name' => 'Customer label',
        ]);
        Http::assertNothingSent();
    }

    #[TestWith(['credit-card'])]
    #[TestWith(['credit.card'])]
    #[TestWith(['credit/card'])]
    #[TestWith(['credit card'])]
    #[TestWith(['信用卡'])]
    public function test_creating_with_an_invalid_identifier_reports_the_format_rules(string $identifier): void
    {
        $this->arrangeAdministrator();
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm($this->creationData($identifier))
            ->call('create')->assertHasFormErrors(['method_code' => 'regex'])
            ->assertSee(__('admin.payment_method.validation.method_code_format'));

        $this->assertDatabaseCount('payment_methods', 0);
        Http::assertNothingSent();
    }

    public function test_creating_with_an_overlong_identifier_is_rejected(): void
    {
        $this->arrangeAdministrator();
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm($this->creationData(str_repeat('a', 51)))
            ->call('create')->assertHasFormErrors(['method_code' => 'max']);

        $this->assertDatabaseCount('payment_methods', 0);
        Http::assertNothingSent();
    }

    public function test_identifier_remains_globally_unique(): void
    {
        $this->arrangeAdministrator();
        $this->makePaymentMethod('existing_route');
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm($this->creationData('existing_route'))
            ->call('create')->assertHasFormErrors(['method_code' => 'unique'])
            ->assertSee(__('admin.payment_method.validation.method_code_duplicate'));

        $this->assertDatabaseCount('payment_methods', 1);
        Http::assertNothingSent();
    }

    public function test_soft_deleted_identifier_can_be_used_for_a_new_channel(): void
    {
        $this->arrangeAdministrator();
        $this->makePaymentMethod('reusable_route')->delete();
        Http::preventStrayRequests();

        Livewire::test(CreatePaymentMethod::class)->fillForm($this->creationData('reusable_route'))
            ->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseHas('payment_methods', ['method_code' => 'reusable_route', 'deleted_at' => null]);
        Http::assertNothingSent();
    }

    #[TestWith(['old_name'])]
    #[TestWith(['Legacy-123'])]
    public function test_renaming_an_existing_channel_keeps_its_identifier_and_legacy_format(string $identifier): void
    {
        $this->arrangeAdministrator();
        $method = $this->makePaymentMethod($identifier);
        $method->update(['method_name' => 'Old Name']);
        $this->fakeGateway();

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->set('data.method_name', 'Renamed Channel')->assertSet('data.method_code', $identifier)
            ->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas('payment_methods', ['id' => $method->id, 'method_code' => $identifier, 'method_name' => 'Renamed Channel']);
        Http::assertSentCount(1);
    }

    public function test_replacing_an_existing_identifier_requires_the_new_format(): void
    {
        $this->arrangeAdministrator();
        $method = $this->makePaymentMethod('Legacy-123');
        Http::preventStrayRequests();

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm(['method_code' => 'Another-123'])->call('save')
            ->assertHasFormErrors(['method_code' => 'regex']);

        $this->assertSame('Legacy-123', $method->fresh()->method_code);
        Http::assertNothingSent();
    }

    public function test_existing_identifier_can_be_edited_manually_to_a_valid_value(): void
    {
        $this->arrangeAdministrator();
        $method = $this->makePaymentMethod('Legacy-123');
        $this->fakeGateway();

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm(['method_code' => 'Replacement_Route_2026'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('Replacement_Route_2026', $method->fresh()->method_code);
        Http::assertSentCount(1);
    }

    private function arrangeAdministrator(): void
    {
        $this->createMerchantAndApplication();
        $this->actingAs(User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]));
    }

    /** @return array<string, string> */
    private function creationData(string $identifier): array
    {
        return [
            'method_name' => 'Card Route', 'method_code' => $identifier,
            'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck_test', 'domain_client_sk' => 'cs_test',
        ];
    }

    private function fakeGateway(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://shop.example.com/wp-json/payment-plugin/v1/gateway-config?consumer_key=ck_test&consumer_secret=cs_test' => Http::response([
                'code' => 0, 'data' => ['config_id' => 'test-config'],
            ]),
        ]);
    }
}

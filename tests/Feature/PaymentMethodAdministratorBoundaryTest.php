<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentMethodResource\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\PaymentMethodOwnershipService;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaymentMethodAdministratorBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_create_page_point_selection_is_drafted_until_creation_and_initializes_selected_merchants(): void
    {
        Http::preventStrayRequests();
        $owner = $this->administrator();
        $selected = $this->merchant($owner, ['name' => 'Selected merchant', 'default_fee_percent' => '2.5']);
        $removed = $this->merchant($owner, ['name' => 'Removed merchant']);

        $page = Livewire::actingAs($owner)->test(CreatePaymentMethod::class)
            ->assertSee(__('admin.payment_method.authorization.create_description'))
            ->assertSeeHtml("setMerchantAuthorization({$selected->id}, true)")
            ->call('setMerchantAuthorization', $selected->id, true)->assertOk()
            ->call('setMerchantAuthorization', $selected->id, true)->assertOk()
            ->call('setMerchantAuthorization', $removed->id, true)->assertOk()
            ->call('setMerchantAuthorization', $removed->id, false)->assertOk()
            ->assertSet('data.assignedMerchants', [$selected->id])
            ->assertSeeHtml("setMerchantAuthorization({$selected->id}, false)")
            ->assertSeeHtml("setMerchantAuthorization({$removed->id}, true)");

        $this->assertDatabaseCount('payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_settings', 0);

        $page->fillForm($this->creationData())->call('create')->assertHasNoFormErrors()->assertRedirect();

        $method = PaymentMethod::query()->sole();
        $this->assertDatabaseCount('merchant_payment_methods', 1);
        $this->assertDatabaseHas('merchant_payment_methods', ['payment_method_id' => $method->id, 'merchant_id' => $selected->id]);
        $this->assertDatabaseHas('merchant_payment_settings', ['payment_method_id' => $method->id, 'merchant_id' => $selected->id, 'fee_percent' => '2.5']);
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_create_page_hides_and_rejects_merchants_outside_the_creators_administrator_scope(bool $super): void
    {
        $actor = $this->administrator($super);
        $foreign = $this->merchant($this->administrator());
        $inactive = $this->merchant($actor, ['status' => false]);
        $platform = Merchant::create(['name' => 'Platform merchant', 'status' => true]);

        $page = Livewire::actingAs($actor)->test(CreatePaymentMethod::class)
            ->assertDontSeeHtml("setMerchantAuthorization({$foreign->id}, true)")
            ->assertDontSeeHtml("setMerchantAuthorization({$inactive->id}, true)");

        if ($super) {
            $page->assertSeeHtml("setMerchantAuthorization({$platform->id}, true)");
        } else {
            $page->assertDontSeeHtml("setMerchantAuthorization({$platform->id}, true)");
        }

        $page->call('setMerchantAuthorization', $foreign->id, true)->assertForbidden();
        $this->assertDatabaseCount('payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    #[TestWith(['disabled'])]
    #[TestWith(['transferred'])]
    #[TestWith(['deleted'])]
    public function test_creation_rechecks_selected_merchants_before_saving(string $change): void
    {
        Http::preventStrayRequests();
        $actor = $this->administrator();
        $merchant = $this->merchant($actor);
        $page = Livewire::actingAs($actor)->test(CreatePaymentMethod::class)
            ->call('setMerchantAuthorization', $merchant->id, true)->assertOk()
            ->fillForm($this->creationData());

        match ($change) {
            'disabled' => $merchant->update(['status' => false]),
            'transferred' => $merchant->update(['owner_id' => $this->administrator()->id]),
            'deleted' => $merchant->delete(),
        };

        $page->call('create')->assertHasFormErrors(['assignedMerchants' => 'in'])
            ->assertSee(__('validation.in', ['attribute' => __('admin.payment_method.fields.assigned_merchants')]));
        $this->assertDatabaseCount('payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
        Http::assertNothingSent();
    }

    public function test_forged_create_selection_is_rejected_without_partial_records(): void
    {
        $actor = $this->administrator();
        $allowed = $this->merchant($actor);
        $foreign = $this->merchant($this->administrator());

        Livewire::actingAs($actor)->test(CreatePaymentMethod::class)
            ->fillForm([...$this->creationData(), 'assignedMerchants' => [$allowed->id, $foreign->id]])
            ->call('create')->assertHasFormErrors(['assignedMerchants' => 'in']);
        $this->assertDatabaseCount('payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    public function test_changing_to_merchant_owned_creation_clears_draft_authorizations(): void
    {
        Http::preventStrayRequests();
        $actor = $this->administrator();
        $merchant = $this->merchant($actor);

        $page = Livewire::actingAs($actor)->test(CreatePaymentMethod::class)
            ->call('setMerchantAuthorization', $merchant->id, true)->assertOk()
            ->set('data.merchant_id', $merchant->id)
            ->assertSet('data.assignedMerchants', [])
            ->assertDontSeeHtml('merchant-authorization-unassigned')
            ->set('data.merchant_id', null)
            ->assertSet('data.assignedMerchants', [])
            ->assertSeeHtml("setMerchantAuthorization({$merchant->id}, true)")
            ->set('data.merchant_id', $merchant->id);

        $page->fillForm($this->creationData())->call('create')->assertHasNoFormErrors()->assertRedirect();
        $this->assertSame($merchant->id, PaymentMethod::query()->sole()->merchant_id);
        $this->assertDatabaseCount('merchant_payment_methods', 0);
        Http::assertNothingSent();
    }

    public function test_create_point_selection_rechecks_operator_status(): void
    {
        $actor = $this->administrator();
        $merchant = $this->merchant($actor);
        $page = Livewire::actingAs($actor)->test(CreatePaymentMethod::class);
        $actor->update(['status' => false]);

        $page->call('setMerchantAuthorization', $merchant->id, true)->assertForbidden();
        $this->assertDatabaseCount('payment_methods', 0);
        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    public function test_manager_cannot_see_foreign_channels_even_with_legacy_assignment_and_merchant_filter(): void
    {
        $manager = $this->administrator();
        $other = $this->administrator();
        $merchant = $this->merchant($manager);
        $foreignMerchant = $this->merchant($other);
        $own = $this->method($manager, 'own');
        $merchantOwned = $this->method($manager, 'merchant', ['merchant_id' => $merchant->id]);
        $foreign = $this->method($other, 'foreign');
        $foreignOwned = $this->method($other, 'foreign-owned', ['merchant_id' => $foreignMerchant->id]);
        $this->legacyAssignment($merchant, $foreign);
        $own->assignedMerchants()->attach($merchant);

        $page = Livewire::actingAs($manager)->test(ListPaymentMethods::class)
            ->assertCanSeeTableRecords([$own, $merchantOwned])
            ->assertCanNotSeeTableRecords([$foreign, $foreignOwned]);
        $page->filterTable('merchant_id', $merchant->id)
            ->assertCanSeeTableRecords([$own, $merchantOwned])
            ->assertCanNotSeeTableRecords([$foreign, $foreignOwned]);
        Livewire::test(EditPaymentMethod::class, ['record' => $foreign->id])->assertNotFound();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_cross_administrator_authorization_is_rejected_even_for_super_admin(bool $super): void
    {
        $owner = $this->administrator();
        $actor = $super ? $this->administrator(true) : $owner;
        $method = $this->method($owner, 'boundary');
        $foreignMerchant = $this->merchant($this->administrator());

        Livewire::actingAs($actor)->test(EditPaymentMethod::class, ['record' => $method->id])
            ->assertActionDoesNotExist('authorizeMerchants')
            ->assertDontSeeHtml("setMerchantAuthorization({$foreignMerchant->id}, true)")
            ->call('setMerchantAuthorization', $foreignMerchant->id, true)->assertForbidden();
        $this->assertDatabaseMissing('merchant_payment_methods', ['merchant_id' => $foreignMerchant->id, 'payment_method_id' => $method->id]);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
    }

    public function test_pivot_blocks_cross_administrator_assignment_from_either_relationship(): void
    {
        $owner = $this->administrator();
        $merchant = $this->merchant($this->administrator());
        $method = $this->method($owner, 'pivot-boundary');

        foreach ([true, false] as $reverse) {
            try {
                if ($reverse) {
                    $merchant->assignedPaymentMethods()->attach($method);
                } else {
                    $method->assignedMerchants()->attach($merchant);
                }
                $this->fail('Cross-administrator assignment must fail.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('merchant_payment_methods', 0);
            }
        }
    }

    public function test_point_selection_initializes_defaults_and_revoke_cleans_references_without_saving_form(): void
    {
        Http::preventStrayRequests();
        $owner = $this->administrator();
        $merchant = $this->merchant($owner, ['default_fee_percent' => '2.5', 'settlement_mode' => 'shipped', 'settlement_days' => 7]);
        $method = $this->method($owner, 'point-selection');
        $group = PaymentGroup::create(['merchant_id' => $merchant->id, 'group_key' => 'point', 'group_name' => 'Point']);

        $page = Livewire::actingAs($owner)->test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm(['method_name' => 'Unsaved name', 'remark' => 'Unsaved remark'])
            ->assertActionDoesNotExist('authorizeMerchants')
            ->assertSeeHtml("setMerchantAuthorization({$merchant->id}, true)")
            ->call('setMerchantAuthorization', $merchant->id, true)->assertOk()
            ->assertSeeHtml("setMerchantAuthorization({$merchant->id}, false)");
        $this->assertSame('point-selection', $method->fresh()->method_name);
        $this->assertNull($method->fresh()->remark);
        $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $merchant->id, 'payment_method_id' => $method->id, 'fee_percent' => '2.5', 'settlement_days' => 7]);
        $group->paymentMethods()->attach($method);
        $group->delete();
        $merchant->update(['default_fee_percent' => '4']);
        $page->call('setMerchantAuthorization', $merchant->id, true)->assertOk();
        $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $merchant->id, 'fee_percent' => '2.5']);
        $page->call('setMerchantAuthorization', $merchant->id, false)->assertOk();
        $this->assertDatabaseCount('merchant_payment_methods', 0);
        $this->assertDatabaseMissing('payment_group_methods', ['group_id' => $group->id, 'method_id' => $method->id]);
        $this->assertDatabaseCount('merchant_payment_settings', 0);
        $page->call('setMerchantAuthorization', $merchant->id, true)->assertOk();
        $this->assertDatabaseHas('merchant_payment_settings', ['merchant_id' => $merchant->id, 'fee_percent' => '4']);
        $page->fillForm(['remark' => 'Saved note'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Saved note', $method->fresh()->remark);
        $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $method->id]);
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_editor_shows_fixed_administrator_and_saves_remark_without_ownership_changes(bool $super): void
    {
        Http::preventStrayRequests();
        $owner = $this->administrator();
        $other = $this->administrator();
        $actor = $super ? $this->administrator(true) : $owner;
        $merchant = $this->merchant($owner);
        $method = $this->method($owner, 'fixed-owner');

        Livewire::actingAs($actor)->test(EditPaymentMethod::class, ['record' => $method->id])
            ->assertSee($owner->name)
            ->assertActionDoesNotExist('changeOwner')
            ->fillForm(['owner_id' => $other->id, 'merchant_id' => $merchant->id, 'remark' => 'Financial note'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($owner->id, $method->fresh()->owner_id);
        $this->assertNull($method->fresh()->merchant_id);
        $this->assertSame('Financial note', $method->fresh()->remark);
        Http::assertNothingSent();
    }

    public function test_authorization_rechecks_owner_and_actor_after_dialog_is_opened(): void
    {
        $owner = $this->administrator();
        $merchant = $this->merchant($owner);
        $method = $this->method($owner, 'stale');
        $page = Livewire::actingAs($owner)->test(EditPaymentMethod::class, ['record' => $method->id])
            ->assertActionDoesNotExist('authorizeMerchants');
        $merchant->update(['owner_id' => $this->administrator()->id]);
        $page->call('setMerchantAuthorization', $merchant->id, true)->assertForbidden();
        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    public function test_losing_permission_blocks_direct_authorization_calls(): void
    {
        $owner = $this->administrator();
        $merchant = $this->merchant($owner);
        $method = $this->method($owner, 'permission');
        $page = Livewire::actingAs($owner)->test(EditPaymentMethod::class, ['record' => $method->id])
            ->assertActionDoesNotExist('authorizeMerchants');
        $owner->revokePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $page->call('setMerchantAuthorization', $merchant->id, true)->assertForbidden();
        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    public function test_merchant_owned_payment_method_has_no_inline_authorization_panel(): void
    {
        $owner = $this->administrator();
        $merchant = $this->merchant($owner);
        $method = $this->method($owner, 'merchant-owned-panel', ['merchant_id' => $merchant->id]);

        Livewire::actingAs($owner)->test(EditPaymentMethod::class, ['record' => $method->id])
            ->assertActionDoesNotExist('authorizeMerchants')
            ->assertDontSeeHtml('merchant-authorization-unassigned')
            ->assertDontSeeHtml('setMerchantAuthorization(');
    }

    public function test_merchant_names_are_escaped_in_the_inline_authorization_panel(): void
    {
        $owner = $this->administrator();
        $merchant = $this->merchant($owner, ['name' => '<img src=x onerror=alert(1)>']);
        $method = $this->method($owner, 'escaped');

        Livewire::actingAs($owner)->test(EditPaymentMethod::class, ['record' => $method->id])
            ->assertActionDoesNotExist('authorizeMerchants')
            ->assertSee($merchant->name)
            ->assertDontSeeHtml($merchant->name);
    }

    public function test_transferring_an_assigned_channel_cannot_create_cross_administrator_authorization(): void
    {
        $owner = $this->administrator();
        $merchant = $this->merchant($owner);
        $method = $this->method($owner, 'transfer-boundary');
        $method->assignedMerchants()->attach($merchant);
        $target = $this->administrator();

        try {
            app(PaymentMethodOwnershipService::class)->changeOwner($method, $this->administrator(true), $target->id);
            $this->fail('Transfer must preserve the assignment ownership boundary.');
        } catch (AuthorizationException) {
            $this->assertSame($owner->id, $method->fresh()->owner_id);
            $this->assertDatabaseHas('merchant_payment_methods', ['merchant_id' => $merchant->id, 'payment_method_id' => $method->id]);
        }
    }

    private function administrator(bool $super = false): User
    {
        $user = User::factory()->create(['merchant_id' => null, 'is_super_admin' => $super, 'status' => true]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);

        return $user;
    }

    /** @return array<string, string> */
    private function creationData(): array
    {
        return [
            'method_code' => 'draft-method', 'method_name' => 'Draft method',
            'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck_test', 'domain_client_sk' => 'cs_test',
            'product_match_mode' => 'MATCH',
        ];
    }

    private function merchant(User $owner, array $attributes = []): Merchant
    {
        return Merchant::create(['name' => 'Merchant '.$owner->id, 'owner_id' => $owner->id, 'status' => true, ...$attributes]);
    }

    private function method(User $owner, string $code, array $attributes = []): PaymentMethod
    {
        $method = new PaymentMethod(['method_code' => $code, 'method_name' => $code, 'merchant_id' => null, 'domain' => 'https://shop.example.com', 'domain_client_id' => 'ck_test', 'domain_client_sk' => 'cs_test', ...$attributes]);
        $method->owner_id = $owner->id;
        $method->save();

        return $method;
    }

    private function legacyAssignment(Merchant $merchant, PaymentMethod $method): void
    {
        DB::table('merchant_payment_methods')->insert(['merchant_id' => $merchant->id, 'payment_method_id' => $method->id]);
    }
}

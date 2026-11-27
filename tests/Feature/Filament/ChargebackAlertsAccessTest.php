<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ChargebackAlerts\ChargebackAlertResource;
use App\Filament\Resources\ChargebackAlerts\Pages\ManageChargebackAlerts;
use App\Filament\Resources\ChargebackOrders\ChargebackOrderResource;
use App\Filament\Resources\ChargebackProviderAccounts\ChargebackProviderAccountResource;
use App\Filament\Resources\ChargebackProviderAccounts\Pages\CreateChargebackProviderAccount;
use App\Filament\Resources\ChargebackProviderAccounts\Pages\EditChargebackProviderAccount;
use App\Filament\Resources\OrderResource;
use App\Models\Application;
use App\Models\ChargebackAlert;
use App\Models\ChargebackChannelBinding;
use App\Models\ChargebackProviderAccount;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ChargebackAlerts\ChargebackAccountConfiguration;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ChargebackAlertsAccessTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->createMerchantAndApplication();
        Filament::setCurrentPanel('admin');
        app()->setLocale('zh_CN');
    }

    public function test_chargeback_alert_links_are_hidden_from_panel_navigation_and_search(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'status' => true]));

        $navigationUrls = collect(Filament::getNavigation())
            ->flatMap(fn (NavigationGroup $group): array => collect($group->getItems())->all())
            ->map(fn (NavigationItem $item): ?string => $item->getUrl());

        $this->assertContains(ChargebackOrderResource::getUrl(), $navigationUrls);
        $this->assertNotContains(ChargebackAlertResource::getUrl(), $navigationUrls);
        $this->assertNotContains(ChargebackProviderAccountResource::getUrl(), $navigationUrls);
        $this->assertFalse(ChargebackAlertResource::canGloballySearch());
        $this->assertFalse(ChargebackProviderAccountResource::canGloballySearch());
    }

    public function test_platform_admin_chooses_driver_and_clicks_to_bind_multiple_methods_without_exposing_secret(): void
    {
        $actor = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $first = $this->makePaymentMethod('unified-a');
        $second = $this->makePaymentMethod('unified-b');
        $secret = 'only-entry-signing-key-for-test';

        $this->actingAs($actor);
        Livewire::test(CreateChargebackProviderAccount::class)
            ->set('data.provider', 'wetech')
            ->fillForm([
                'name' => 'WeTech Test', 'external_id' => 'config-ui', 'environment' => 'test',
                'timezone' => 'Asia/Shanghai',
                'signing_config' => ['secret_key' => $secret], 'enabled' => true,
            ])->call('create')->assertHasNoFormErrors();
        $account = ChargebackProviderAccount::query()->sole();
        $encryptedCredentials = DB::table('chargeback_provider_accounts')->value('signing_config');
        $this->assertNotSame($secret, $encryptedCredentials);
        $this->assertSame($secret, $account->signing_config['secret_key']);
        $this->assertSame([], $account->services);

        $staleEditor = Livewire::test(EditChargebackProviderAccount::class, ['record' => $account->id]);
        $page = Livewire::test(EditChargebackProviderAccount::class, ['record' => $account->id])
            ->assertDontSee($secret)
            ->call('setChargebackServiceEnabled', 'ethoca', true)
            ->mountAction(TestAction::make('bindPaymentMethods')->arguments(['service' => 'ethoca']))
            ->set('mountedActions.0.data.scope_key', 'SHARED-DESCRIPTOR')
            ->assertMountedActionModalSeeHtml("setChargebackChannelBinding({$first->id}, true)")
            ->call('setChargebackChannelBinding', $first->id, true)
            ->assertMountedActionModalSeeHtml("setChargebackChannelBinding({$first->id}, false)")
            ->call('setChargebackChannelBinding', $second->id, true)
            ->assertMountedActionModalSeeHtml("setChargebackChannelBinding({$second->id}, false)")
            ->call('setChargebackChannelBinding', $first->id, false)
            ->assertMountedActionModalSeeHtml("setChargebackChannelBinding({$first->id}, true)")
            ->assertHasNoErrors()->unmountAction();

        $this->assertDatabaseCount('chargeback_channel_bindings', 2);
        $this->assertNotNull(ChargebackChannelBinding::query()->where('payment_method_id', $first->id)->sole()->valid_until);
        $this->assertNull(ChargebackChannelBinding::query()->where('payment_method_id', $second->id)->sole()->valid_until);
        $page->call('setChargebackServiceEnabled', 'rdr', true)
            ->mountAction(TestAction::make('bindPaymentMethods')->arguments(['service' => 'rdr']))
            ->set('mountedActions.0.data.scope_key', 'SHARED-DESCRIPTOR')
            ->assertMountedActionModalSeeHtml("setChargebackChannelBinding({$second->id}, true)")
            ->call('setChargebackChannelBinding', $first->id, true)
            ->call('setChargebackChannelBinding', $second->id, true)
            ->assertHasNoErrors()->unmountAction()
            ->call('setChargebackServiceEnabled', 'ethoca', false);

        $staleEditor->fillForm(['name' => 'Renamed connection'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['rdr'], $account->fresh()->services);
        $this->assertSame($encryptedCredentials, DB::table('chargeback_provider_accounts')->sole()->signing_config);
        $this->assertDatabaseHas('chargeback_channel_bindings', ['service' => 'ethoca', 'payment_method_id' => $second->id, 'valid_until' => null]);
        foreach ([$first, $second] as $method) {
            $this->assertDatabaseHas('chargeback_channel_bindings', ['service' => 'rdr', 'payment_method_id' => $method->id, 'valid_until' => null]);
        }
        $this->assertDatabaseCount('merchant_payment_methods', 0);
    }

    public function test_manager_cannot_bind_a_foreign_payment_method_using_a_forged_click(): void
    {
        $manager = User::factory()->create(['status' => true, 'merchant_id' => null]);
        $manager->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $account = ChargebackProviderAccount::factory()->create(['owner_id' => $manager->id]);
        $foreign = $this->makePaymentMethod('private-foreign-method');
        $this->actingAs($manager);

        Livewire::test(EditChargebackProviderAccount::class, ['record' => $account->id])
            ->mountAction(TestAction::make('bindPaymentMethods')->arguments(['service' => 'ethoca']))
            ->set('mountedActions.0.data.scope_key', 'SHARED-DESCRIPTOR')
            ->assertMountedActionModalDontSee('PRIVATE-FOREIGN-METHOD')
            ->call('setChargebackChannelBinding', $foreign->id, true)
            ->assertForbidden();
        $this->assertDatabaseCount('chargeback_channel_bindings', 0);
    }

    public function test_a_manager_with_an_open_service_panel_cannot_pause_a_transferred_connection(): void
    {
        $manager = User::factory()->create(['status' => true, 'merchant_id' => null]);
        $manager->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $account = ChargebackProviderAccount::factory()->create(['owner_id' => $manager->id]);
        $this->actingAs($manager);
        $page = Livewire::test(EditChargebackProviderAccount::class, ['record' => $account->id]);
        $account->update(['owner_id' => null]);

        $page->call('setChargebackServiceEnabled', 'ethoca', false)->assertForbidden();
        $this->assertSame(['ethoca', 'rdr'], $account->fresh()->services);
    }

    public function test_every_alert_opens_a_detail_and_candidate_orders_are_scoped_to_the_provider_binding(): void
    {
        $method = $this->makePaymentMethod('scope-on');
        $outside = $this->makePaymentMethod('scope-off');
        $order = $this->makeOrder('BOUND-PAID', 'M1', ['paid_at' => now(), 'status' => 'paid', 'payment_method_id' => $method->id]);
        $other = $this->makeOrder('OUTSIDE-PAID', 'M2', ['paid_at' => now(), 'status' => 'paid', 'payment_method_id' => $outside->id]);
        $account = ChargebackProviderAccount::factory()->create();
        $operator = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        app(ChargebackAccountConfiguration::class)->setBinding($operator, $account->id, $method->id, true, [
            'service' => 'ethoca', 'scope_key' => 'SHOP', 'reference_type' => 'arn', 'valid_from' => now()->subDay()->toDateTimeString(),
        ]);
        $alert = ChargebackAlert::query()->create([
            'chargeback_provider_account_id' => $account->id, 'service' => 'ethoca', 'external_id' => 'unmatched-ref',
            'scope_key' => 'SHOP', 'trade_at' => now(), 'trade_amount' => '100.00', 'trade_currency' => 'USD',
        ]);
        $this->actingAs($operator);
        $rdrAlert = ChargebackAlert::query()->create([
            'chargeback_provider_account_id' => $account->id, 'service' => 'rdr', 'external_id' => 'rdr-ref',
        ]);
        Livewire::test(ManageChargebackAlerts::class)
            ->assertCanSeeTableRecords([$alert, $rdrAlert])
            ->assertSee('WeTech · Ethoca')
            ->filterTable('service', 'ethoca')
            ->assertCanSeeTableRecords([$alert])
            ->assertCanNotSeeTableRecords([$rdrAlert])
            ->mountAction(TestAction::make('view')->table($alert))
            ->assertMountedActionModalSee(__('chargeback_alerts.candidate_orders'))
            ->assertMountedActionModalSee($order->order_no)
            ->assertMountedActionModalSeeHtml('href="'.OrderResource::getUrl('view', ['record' => $order]).'"')
            ->assertMountedActionModalDontSee($other->order_no);
    }

    public function test_merchant_viewer_sees_only_own_matched_alerts(): void
    {
        $account = ChargebackProviderAccount::factory()->create();
        $ownOrder = $this->makeOrder('OWN-ORDER', 'M1');
        $own = ChargebackAlert::query()->create([
            'chargeback_provider_account_id' => $account->id, 'service' => 'ethoca', 'external_id' => 'own-alert',
            'order_id' => $ownOrder->id, 'merchant_id' => $this->merchant->id, 'match_status' => 'matched',
        ]);
        $foreignMerchant = Merchant::query()->create(['name' => 'Private Merchant', 'status' => true]);
        $foreignApp = Application::createWithCredentials([
            'merchant_id' => $foreignMerchant->id, 'name' => 'Private App', 'website' => 'https://other.example',
        ]);
        $foreign = $this->makeOrder('OTHER-ORDER', 'M1', ['merchant_id' => $foreignMerchant->id, 'application_id' => $foreignApp->id]);
        $other = ChargebackAlert::query()->create([
            'chargeback_provider_account_id' => $account->id, 'service' => 'rdr', 'external_id' => 'foreign-alert',
            'order_id' => $foreign->id, 'merchant_id' => $foreignMerchant->id, 'match_status' => 'matched',
        ]);
        $unmatched = ChargebackAlert::query()->create([
            'chargeback_provider_account_id' => $account->id, 'service' => 'rdr', 'external_id' => 'unmatched-alert',
        ]);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo([Permissions::CHARGEBACK_ALERTS_VIEW, Permissions::ORDERS_VIEW]);
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');

        Livewire::test(ManageChargebackAlerts::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other, $unmatched])
            ->mountAction(TestAction::make('view')->table($own))
            ->assertMountedActionModalSee($ownOrder->order_no)
            ->assertMountedActionModalDontSee($foreign->order_no);
    }
}

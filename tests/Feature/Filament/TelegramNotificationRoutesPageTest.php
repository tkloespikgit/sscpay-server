<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TelegramNotificationRoutes;
use App\Filament\Resources\SystemConfigResource;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\PlatformRoleProvisioningService;
use App\Services\TelegramMessageTemplate;
use App\Services\TelegramNotificationRouter;
use App\Services\TelegramNotificationSettings;
use App\Support\TelegramNotificationKind;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class TelegramNotificationRoutesPageTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
    }

    public function test_only_enabled_super_admins_in_the_platform_panel_can_open_and_save(): void
    {
        $this->seed(PermissionSeeder::class);
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->assignRole(app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole());
        $disabledSuper = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => false]);

        foreach ([$manager, $disabledSuper] as $user) {
            $this->actingAs($user);
            $this->assertFalse(TelegramNotificationRoutes::shouldRegisterNavigation());
            Livewire::test(TelegramNotificationRoutes::class)->assertForbidden();
        }

        $super = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        $this->actingAs($super);
        Filament::setCurrentPanel('merchant');
        Livewire::test(TelegramNotificationRoutes::class)->assertForbidden();
        Filament::setCurrentPanel('admin');
        $page = Livewire::test(TelegramNotificationRoutes::class)->assertOk()
            ->call('setNotificationGroup', 'channel')
            ->mountAction(TestAction::make('edit')->table('order_paid'));
        $super->update(['is_super_admin' => false]);
        $page->callMountedAction()->assertForbidden();
        $this->assertDatabaseMissing('system_configs', ['config_key' => TelegramNotificationSettings::CONFIG_KEY]);
    }

    public function test_super_admin_can_save_one_rule_and_the_raw_config_stays_out_of_system_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        Http::preventStrayRequests();

        Livewire::test(TelegramNotificationRoutes::class)
            ->call('setNotificationGroup', 'channel')
            ->callAction(TestAction::make('edit')->table('order_paid'), [
                'enabled' => false, 'template' => 'Payment :order_no :amount :currency', 'repeat_count' => 2,
            ])
            ->assertHasNoActionErrors()->assertNotified();

        $this->assertSame(['enabled' => false, 'template' => 'Payment :order_no :amount :currency', 'repeat_count' => 2],
            app(TelegramNotificationSettings::class)->get(TelegramNotificationKind::OrderPaid));
        $this->assertNull(SystemConfigResource::getEloquentQuery()->where('config_key', TelegramNotificationSettings::CONFIG_KEY)->first());
        Http::assertNothingSent();
    }

    public function test_tabs_search_and_filter_show_the_expected_notification_groups(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        app(TelegramNotificationSettings::class)->save(TelegramNotificationKind::OrderPaid, [
            'enabled' => false, 'template' => '', 'repeat_count' => 5,
        ]);
        $page = Livewire::test(TelegramNotificationRoutes::class)
            ->assertCanSeeTableRecords(['no_available_payment_method', 'logistics_anomaly'])
            ->assertCanNotSeeTableRecords(['order_paid', 'designated_order_created'])
            ->call('setNotificationGroup', 'channel')
            ->assertCanSeeTableRecords(['order_paid', 'designated_order_created', 'site_products_sync_completed'])
            ->searchTable(__('admin.telegram_routes.kinds.order_paid.label'));
        $this->assertCount(1, $page->instance()->getTableRecords());
        $page->searchTable('')->filterTable('enabled', '0')
            ->assertCanSeeTableRecords(['order_paid']);
    }

    public function test_invalid_template_is_shown_as_an_action_error(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        Livewire::test(TelegramNotificationRoutes::class)
            ->callAction(TestAction::make('edit')->table('logistics_anomaly'), ['enabled' => true, 'template' => ':bot_token'])
            ->assertHasActionErrors(['template']);
        $this->assertDatabaseMissing('system_configs', ['config_key' => TelegramNotificationSettings::CONFIG_KEY]);
    }

    public function test_recipient_preview_resolves_channel_owner_without_exposing_bot_credentials_or_sending(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('preview-channel');
        TelegramBot::create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'private-token',
            'chat_id' => 'private-chat', 'is_enabled' => true,
        ]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        Http::preventStrayRequests();

        $page = Livewire::test(TelegramNotificationRoutes::class)->call('setNotificationGroup', 'channel')
            ->mountAction(TestAction::make('recipient')->table('designated_order_created'))
            ->assertActionMounted(TestAction::make('recipient')->table('designated_order_created'))
            ->fillForm(['context_id' => $method->id])
            ->assertActionDataSet(['context_id' => $method->id]);

        $modal = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        $this->assertStringContainsString($this->merchant->name, $modal);
        $this->assertStringContainsString(__('admin.telegram_routes.recipient_statuses.ready'), $modal);
        $this->assertStringNotContainsString('private-token', $modal);
        $this->assertStringNotContainsString('private-chat', $modal);

        Http::assertNothingSent();
    }

    public function test_restoring_defaults_only_changes_the_selected_notification(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        $settings = app(TelegramNotificationSettings::class);
        $settings->save(TelegramNotificationKind::OrderPaid, ['enabled' => false, 'template' => 'Custom Ref: :order.merchant_order_no', 'repeat_count' => 1]);
        $settings->save(TelegramNotificationKind::OrderDisputing, ['enabled' => false, 'template' => 'Dispute']);
        Livewire::test(TelegramNotificationRoutes::class)->call('setNotificationGroup', 'channel')
            ->callAction(TestAction::make('reset')->table('order_paid'))->assertNotified();

        $this->assertSame(['enabled' => true, 'template' => '', 'repeat_count' => 5], $settings->get(TelegramNotificationKind::OrderPaid));
        $this->assertFalse($settings->get(TelegramNotificationKind::OrderDisputing)['enabled']);
    }

    public function test_one_editor_controls_the_complete_preview_and_sent_message(): void
    {
        $this->app->setLocale('zh_CN');
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('complete-message');
        TelegramBot::create(['merchant_id' => $this->merchant->id, 'bot_token' => 'preview-bot', 'chat_id' => 'preview-chat', 'is_enabled' => true]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        Http::preventStrayRequests();
        $template = "交易：:order.transaction_id\n付款确认 <&>\n客户订单：:order.merchant_order_no\n实收：:order.amount :order.currency";
        $expected = "交易：TX-DEMO-001\n付款确认 &lt;&amp;&gt;\n客户订单：SHOP-DEMO-001\n实收：100.00 USD";

        $page = Livewire::test(TelegramNotificationRoutes::class)->call('setNotificationGroup', 'channel')
            ->mountAction(TestAction::make('edit')->table('order_paid'))
            ->assertFormFieldExists('template')
            ->assertFormFieldDoesNotExist('order_template')
            ->assertActionDataSet(['template' => TelegramMessageTemplate::defaultTemplate(TelegramNotificationKind::OrderPaid)])
            ->fillForm(['template' => $template]);

        $modal = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        $this->assertStringContainsString(nl2br($expected), $modal);
        $this->assertStringNotContainsString('系统订单号：SYS-DEMO-001', $modal);

        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified()
            ->mountAction(TestAction::make('edit')->table('order_paid'))
            ->assertActionDataSet(['template' => $template]);
        $this->assertSame($template, app(TelegramNotificationSettings::class)->get(TelegramNotificationKind::OrderPaid)['template']);
        Http::assertNothingSent();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = app(TelegramNotificationRouter::class)->sendNotification(TelegramNotificationKind::OrderPaid, [], method: $method, orderDetails: [
            'order_no' => 'SYS-DEMO-001', 'transaction_id' => 'TX-DEMO-001', 'merchant_order_no' => 'SHOP-DEMO-001', 'amount' => '100.00', 'currency' => 'USD',
        ]);

        $this->assertTrue($delivery->sent);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === 'preview-chat' && $request['text'] === $expected);
    }

    public function test_digest_preview_repeats_the_edited_order_content_and_inserts_variables_inside_the_block(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        $page = Livewire::test(TelegramNotificationRoutes::class)
            ->mountAction(TestAction::make('edit')->table('unshipped_overdue'))
            ->fillForm(['template' => "Ship after :hours hours\n[orders]\n:order.order_no\n[/orders]\nThanks", 'unshipped_days' => 3]);

        $modal = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        $this->assertStringContainsString(nl2br("Ship after 72 hours\nSYS-DEMO-001\n\nSYS-DEMO-002\nThanks"), $modal);

        $page->set('mountedActions.0.data.insert_variable', 'order.transaction_id')
            ->assertActionDataSet(['template' => "Ship after :hours hours\n[orders]\n:order.order_no\n:order.transaction_id\n[/orders]\nThanks"])
            ->callMountedAction()->assertHasNoActionErrors();
    }

    public function test_product_sync_does_not_offer_order_information_fields(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
        Livewire::test(TelegramNotificationRoutes::class)->call('setNotificationGroup', 'channel')
            ->mountAction(TestAction::make('edit')->table('site_products_sync_completed'))
            ->assertFormFieldDoesNotExist('order_template');
    }
}

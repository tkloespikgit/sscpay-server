<?php

namespace Tests\Feature;

use App\Jobs\SyncSiteProductsJob;
use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\PaymentMethodFailureService;
use App\Services\TelegramNotificationRouter;
use App\Services\TelegramNotificationSettings;
use App\Services\WooCommerceProductSyncService;
use App\Support\TelegramNotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class TelegramNotificationRouterTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_saved_legacy_recipients_cannot_override_the_channel_owner(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('merchant-channel');
        $method->update(['max_failed_times' => 1]);
        $this->adminWithBot();
        $this->merchantBot($this->merchant, 'merchant-chat');
        SystemConfig::set(TelegramNotificationRouter::CONFIG_KEY, ['payment_method_creation_failed_disabled' => ['super_admins']]);
        $order = $this->makeOrder('FAIL-ROUTE', 'route-fail', ['payment_method_id' => $method->id, 'payment_method' => $method->method_code]);
        $this->fakeTelegram();

        app(PaymentMethodFailureService::class)->recordFailure($order, $method, $order->gatewayOrderReference());

        $this->assertSame(['merchant-chat'], $this->chatIds());
        $this->assertFalse($method->refresh()->is_active);
    }

    public function test_do_not_send_suppresses_the_channel_disable_notification(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('silent-channel');
        $method->update(['max_failed_times' => 1]);
        $this->merchantBot($this->merchant, 'merchant-chat');
        SystemConfig::set(TelegramNotificationRouter::CONFIG_KEY, ['payment_method_creation_failed_disabled' => ['none']]);
        $order = $this->makeOrder('FAIL-SILENT', 'silent-fail', ['payment_method_id' => $method->id, 'payment_method' => $method->method_code]);
        Http::preventStrayRequests();

        app(PaymentMethodFailureService::class)->recordFailure($order, $method, $order->gatewayOrderReference());

        Http::assertNothingSent();
        $this->assertFalse($method->refresh()->is_active);
    }

    #[DataProvider('channelNotifications')]
    public function test_system_channel_notifications_only_go_to_the_current_owning_admin(TelegramNotificationKind $kind): void
    {
        $this->createMerchantAndApplication();
        $owner = $this->adminWithBot();
        $this->adminWithBot('other-admin');
        $this->merchantBot($this->merchant, 'order-merchant');
        $method = $this->makePaymentMethod('system-channel');
        $method->forceFill(['merchant_id' => null, 'owner_id' => $owner->id])->save();
        $method->assignedMerchants()->attach($this->merchant->id);
        SystemConfig::set(TelegramNotificationRouter::CONFIG_KEY, [$kind->value => ['merchant', 'channel_merchants', 'super_admins']]);
        $this->fakeTelegram();

        app(TelegramNotificationRouter::class)->send($kind, 'Channel message', $this->merchant->id, $method);

        $this->assertSame(['owner-admin'], $this->chatIds());
    }

    #[DataProvider('channelNotifications')]
    public function test_merchant_channel_notifications_go_to_the_channel_merchant_not_the_order_merchant(TelegramNotificationKind $kind): void
    {
        $this->createMerchantAndApplication();
        $other = Merchant::query()->create(['name' => 'Order merchant']);
        $this->merchantBot($other, 'other-merchant');
        $this->merchantBot($this->merchant, 'channel-merchant');
        $this->adminWithBot();
        $method = $this->makePaymentMethod('merchant-channel');
        $this->fakeTelegram();

        app(TelegramNotificationRouter::class)->send($kind, 'Channel message', $other->id, $method);

        $this->assertSame(['channel-merchant'], $this->chatIds());
    }

    #[DataProvider('merchantNotifications')]
    public function test_merchant_notifications_only_go_to_the_event_merchant(TelegramNotificationKind $kind): void
    {
        $this->createMerchantAndApplication();
        $owner = $this->adminWithBot();
        $this->merchantBot($this->merchant, 'order-merchant');
        $method = $this->makePaymentMethod('system-channel');
        $method->forceFill(['merchant_id' => null, 'owner_id' => $owner->id])->save();
        SystemConfig::set(TelegramNotificationRouter::CONFIG_KEY, [$kind->value => ['channel_owner', 'super_admins']]);
        $this->fakeTelegram();

        app(TelegramNotificationRouter::class)->send($kind, 'Merchant message', $this->merchant->id, $method);

        $this->assertSame(['order-merchant'], $this->chatIds());
    }

    public function test_an_unknown_saved_recipient_does_not_change_the_ownership_rule(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('fallback-channel');
        $this->merchantBot($this->merchant, 'merchant-chat');
        SystemConfig::set(TelegramNotificationRouter::CONFIG_KEY, ['payment_method_forbidden' => ['not-a-recipient']]);
        $this->fakeTelegram();

        app(TelegramNotificationRouter::class)->send(TelegramNotificationKind::PaymentMethodForbidden, 'disabled', null, $method);

        $this->assertSame(['merchant-chat'], $this->chatIds());
    }

    public function test_missing_owner_does_not_broadcast_to_super_admins_or_assigned_merchants(): void
    {
        $this->createMerchantAndApplication();
        $this->adminWithBot();
        $this->merchantBot($this->merchant, 'merchant-chat');
        $method = $this->makePaymentMethod('unowned-channel');
        $method->forceFill(['merchant_id' => null, 'owner_id' => null])->save();
        $method->assignedMerchants()->attach($this->merchant->id);
        Http::preventStrayRequests();

        $delivery = app(TelegramNotificationRouter::class)->send(TelegramNotificationKind::SiteProductsSyncCompleted, 'synced', $this->merchant->id, $method);

        Http::assertNothingSent();
        $this->assertFalse($delivery->anyUsable);
        $this->assertSame('missing_owner', app(TelegramNotificationRouter::class)->recipient(TelegramNotificationKind::SiteProductsSyncCompleted, paymentMethodId: $method->id)['reason']);
    }

    public function test_a_transferred_channel_uses_the_saved_owner_even_with_an_old_model_instance(): void
    {
        $this->createMerchantAndApplication();
        $owner = $this->adminWithBot();
        $nextOwner = $this->adminWithBot('new-admin');
        $method = $this->makePaymentMethod('transferred-channel');
        $method->forceFill(['merchant_id' => null, 'owner_id' => $owner->id])->save();
        $method->fresh()->forceFill(['owner_id' => $nextOwner->id])->save();
        $this->fakeTelegram();

        app(TelegramNotificationRouter::class)->send(TelegramNotificationKind::DesignatedOrderCreated, 'created', $this->merchant->id, $method);

        $this->assertSame(['new-admin'], $this->chatIds());
    }

    public function test_a_disabled_owner_is_skipped_without_falling_back_to_another_recipient(): void
    {
        $this->createMerchantAndApplication();
        $owner = $this->adminWithBot();
        $owner->update(['status' => false]);
        $this->merchantBot($this->merchant, 'merchant-chat');
        $method = $this->makePaymentMethod('disabled-owner');
        $method->forceFill(['merchant_id' => null, 'owner_id' => $owner->id])->save();
        Http::preventStrayRequests();

        app(TelegramNotificationRouter::class)->send(TelegramNotificationKind::OrderPaid, 'paid', $this->merchant->id, $method);

        Http::assertNothingSent();
    }

    public function test_enabled_rules_still_respect_the_recipient_bot_switch(): void
    {
        $this->createMerchantAndApplication();
        $bot = $this->merchantBot($this->merchant, 'merchant-chat');
        $bot->update(['is_enabled' => false]);
        Http::preventStrayRequests();

        $delivery = app(TelegramNotificationRouter::class)->send(TelegramNotificationKind::LogisticsImportCompleted, 'done', $this->merchant->id);

        Http::assertNothingSent();
        $this->assertFalse($delivery->anyUsable);
    }

    #[DataProvider('productSyncResults')]
    public function test_product_sync_job_uses_the_configured_body_and_channel_owner(bool $succeeded): void
    {
        $this->createMerchantAndApplication();
        $owner = $this->adminWithBot();
        $this->merchantBot($this->merchant, 'assigned-merchant');
        $method = $this->makePaymentMethod('sync-channel');
        $method->forceFill(['merchant_id' => null, 'owner_id' => $owner->id])->save();
        $method->assignedMerchants()->attach($this->merchant->id);
        $kind = $succeeded ? TelegramNotificationKind::SiteProductsSyncCompleted : TelegramNotificationKind::SiteProductsSyncFailed;
        app(TelegramNotificationSettings::class)->save($kind, [
            'enabled' => true, 'template' => $succeeded ? 'Synced :domain / :created new' : 'Sync failed :domain / :error',
        ]);
        $this->fakeTelegram();
        $job = new SyncSiteProductsJob($method->id);

        if ($succeeded) {
            $sync = $this->mock(WooCommerceProductSyncService::class);
            $sync->shouldReceive('sync')->once()->withArgs(fn (PaymentMethod $channel): bool => $channel->is($method))
                ->andReturn(['total' => 3, 'created' => 2, 'updated' => 1, 'deleted' => 0, 'skipped' => 0]);
            $job->handle($sync, app(TelegramNotificationRouter::class));
        } else {
            $job->failed(new RuntimeException('Gateway <unavailable>'));
        }

        $this->assertSame(['owner-admin'], $this->chatIds());
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], $method->domain)
            && str_contains($request['text'], $succeeded ? '2 new' : 'Gateway &lt;unavailable&gt;'));
    }

    public static function productSyncResults(): array
    {
        return ['completed' => [true], 'failed' => [false]];
    }

    public static function channelNotifications(): array
    {
        return [
            'forbidden' => [TelegramNotificationKind::PaymentMethodForbidden],
            'creation disabled' => [TelegramNotificationKind::PaymentMethodCreationFailedDisabled],
            'link failed' => [TelegramNotificationKind::PaymentLinkCreationFailed],
            'source created' => [TelegramNotificationKind::DesignatedOrderCreated],
            'source paid' => [TelegramNotificationKind::OrderPaid],
            'sync completed' => [TelegramNotificationKind::SiteProductsSyncCompleted],
            'sync failed' => [TelegramNotificationKind::SiteProductsSyncFailed],
        ];
    }

    public static function merchantNotifications(): array
    {
        return [
            [TelegramNotificationKind::NoAvailablePaymentMethod], [TelegramNotificationKind::OrderDisputing],
            [TelegramNotificationKind::OrderRefundGateway], [TelegramNotificationKind::OrderChargebackGateway],
            [TelegramNotificationKind::GatewayReversalSettleFailed], [TelegramNotificationKind::GatewayReversalAmountMismatch],
            [TelegramNotificationKind::DisputeReviewGatewayStatusIgnored], [TelegramNotificationKind::OrderDisputeOpened],
            [TelegramNotificationKind::OrderDisputeDueSoon], [TelegramNotificationKind::LogisticsImportCompleted],
            [TelegramNotificationKind::LogisticsAnomaly], [TelegramNotificationKind::UnshippedOverdue], [TelegramNotificationKind::ShipmentSyncFailed],
        ];
    }

    private function adminWithBot(string $chat = 'owner-admin'): User
    {
        $user = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        TelegramBot::query()->create(['merchant_id' => null, 'user_id' => $user->id, 'bot_token' => 'test-token', 'chat_id' => $chat, 'is_enabled' => true]);

        return $user;
    }

    private function merchantBot(Merchant $merchant, string $chat): TelegramBot
    {
        return TelegramBot::query()->create(['merchant_id' => $merchant->id, 'bot_token' => 'test-token', 'chat_id' => $chat, 'is_enabled' => true]);
    }

    private function fakeTelegram(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function chatIds(): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'))
            ->map(fn (array $entry): string => (string) $entry[0]['chat_id'])->all();
    }
}

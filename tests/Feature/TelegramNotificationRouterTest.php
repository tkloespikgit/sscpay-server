<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\SystemConfig;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\PaymentMethodFailureService;
use App\Services\TelegramNotificationRouter;
use App\Support\TelegramNotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class TelegramNotificationRouterTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_a_saved_recipient_overrides_the_channel_owner_default(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('merchant-channel');
        $method->update(['max_failed_times' => 1]);
        $admin = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        TelegramBot::query()->create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'merchant-token', 'chat_id' => 'merchant-chat', 'is_enabled' => true,
        ]);
        TelegramBot::query()->create([
            'user_id' => $admin->id, 'merchant_id' => null, 'bot_token' => 'admin-token', 'chat_id' => 'admin-chat', 'is_enabled' => true,
        ]);
        $routes = app(TelegramNotificationRouter::class);
        $selection = $routes->current();
        $selection[TelegramNotificationKind::PaymentMethodCreationFailedDisabled->value] = ['super_admins'];
        $routes->save($selection);
        $order = $this->makeOrder('FAIL-ROUTE', 'route-fail', [
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(PaymentMethodFailureService::class)->recordFailure($order, $method, $order->gatewayOrderReference());

        $chatIds = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'))
            ->map(fn (array $entry): string => (string) $entry[0]['chat_id'])
            ->all();
        $this->assertSame(['admin-chat'], $chatIds);
    }

    public function test_do_not_send_suppresses_the_channel_disable_notification(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('silent-channel');
        $method->update(['max_failed_times' => 1]);
        TelegramBot::query()->create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'merchant-token', 'chat_id' => 'merchant-chat', 'is_enabled' => true,
        ]);
        $routes = app(TelegramNotificationRouter::class);
        $selection = $routes->current();
        $selection[TelegramNotificationKind::PaymentMethodCreationFailedDisabled->value] = ['none'];
        $routes->save($selection);
        $order = $this->makeOrder('FAIL-SILENT', 'silent-fail', [
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        Http::preventStrayRequests();

        app(PaymentMethodFailureService::class)->recordFailure($order, $method, $order->gatewayOrderReference());

        Http::assertNothingSent();
        $this->assertFalse($method->refresh()->is_active);
    }

    public function test_overlapping_merchant_recipients_are_notified_once(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('shared-route');
        TelegramBot::query()->create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'merchant-token', 'chat_id' => 'merchant-chat', 'is_enabled' => true,
        ]);
        $routes = app(TelegramNotificationRouter::class);
        $selection = $routes->current();
        $selection[TelegramNotificationKind::PaymentLinkCreationFailed->value] = ['merchant', 'channel_owner', 'channel_merchants'];
        $routes->save($selection);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $routes->send(
            TelegramNotificationKind::PaymentLinkCreationFailed,
            'link failed',
            $this->merchant->id,
            $method,
        );

        $chatIds = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'))
            ->map(fn (array $entry): string => (string) $entry[0]['chat_id'])
            ->all();
        $this->assertSame(['merchant-chat'], $chatIds);
    }

    public function test_product_sync_defaults_to_merchants_using_the_channel(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('catalog');
        $method->forceFill(['merchant_id' => null, 'owner_id' => null])->save();
        $method->assignedMerchants()->attach($this->merchant->id);
        $other = Merchant::query()->create(['name' => 'Unassigned']);
        TelegramBot::query()->create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'assigned-token', 'chat_id' => 'assigned-chat', 'is_enabled' => true,
        ]);
        TelegramBot::query()->create([
            'merchant_id' => $other->id, 'bot_token' => 'other-token', 'chat_id' => 'other-chat', 'is_enabled' => true,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramNotificationRouter::class)->send(
            TelegramNotificationKind::SiteProductsSyncCompleted,
            'sync done',
            null,
            $method,
        );

        $chatIds = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'))
            ->map(fn (array $entry): string => (string) $entry[0]['chat_id'])
            ->all();
        $this->assertSame(['assigned-chat'], $chatIds);
    }

    public function test_an_unknown_saved_recipient_falls_back_to_the_default(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('fallback-channel');
        $method->update(['max_failed_times' => 1]);
        TelegramBot::query()->create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'merchant-token', 'chat_id' => 'merchant-chat', 'is_enabled' => true,
        ]);
        SystemConfig::set(TelegramNotificationRouter::CONFIG_KEY, [
            TelegramNotificationKind::PaymentMethodCreationFailedDisabled->value => ['not-a-recipient'],
        ]);
        $order = $this->makeOrder('FAIL-FALLBACK', 'fallback-fail', [
            'payment_method_id' => $method->id, 'payment_method' => $method->method_code,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(PaymentMethodFailureService::class)->recordFailure($order, $method, $order->gatewayOrderReference());

        $chatIds = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org/'))
            ->map(fn (array $entry): string => (string) $entry[0]['chat_id'])
            ->all();
        $this->assertSame(['merchant-chat'], $chatIds);
    }

    public function test_saving_do_not_send_with_another_recipient_is_rejected(): void
    {
        $routes = app(TelegramNotificationRouter::class);
        $selection = $routes->current();
        $selection[TelegramNotificationKind::OrderPaid->value] = ['merchant', 'none'];

        try {
            $routes->save($selection);
            $this->fail('不发送不能和其他接收人一起保存');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(TelegramNotificationKind::OrderPaid->value, $exception->errors());
        }

        $this->assertSame('{}', SystemConfig::query()->where('config_key', TelegramNotificationRouter::CONFIG_KEY)->value('config_value'));
    }

    public function test_a_channel_recipient_is_rejected_for_a_notification_without_a_channel(): void
    {
        $routes = app(TelegramNotificationRouter::class);
        $selection = $routes->current();
        $selection[TelegramNotificationKind::LogisticsImportCompleted->value] = ['channel_owner'];

        try {
            $routes->save($selection);
            $this->fail('没有支付通道的通知不能选择通道归属');
        } catch (ValidationException $exception) {
            $this->assertTrue(collect($exception->errors())->keys()->contains(
                fn (string $key): bool => str_starts_with($key, TelegramNotificationKind::LogisticsImportCompleted->value),
            ));
        }

        $this->assertSame('{}', SystemConfig::query()->where('config_key', TelegramNotificationRouter::CONFIG_KEY)->value('config_value'));
    }
}

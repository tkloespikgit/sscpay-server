<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Services\TelegramMessageTemplate;
use App\Services\TelegramNotificationRouter;
use App\Services\TelegramNotificationSettings;
use App\Support\TelegramNotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_disabled_notifications_remain_disabled_until_explicitly_enabled(): void
    {
        SystemConfig::set(TelegramNotificationRouter::CONFIG_KEY, ['order_paid' => ['none']]);
        $settings = app(TelegramNotificationSettings::class);
        $this->assertFalse($settings->get(TelegramNotificationKind::OrderPaid)['enabled']);

        $settings->save(TelegramNotificationKind::OrderPaid, $settings->defaults(TelegramNotificationKind::OrderPaid));

        $this->assertTrue($settings->get(TelegramNotificationKind::OrderPaid)['enabled']);
        $this->assertSame(5, $settings->get(TelegramNotificationKind::OrderPaid)['repeat_count']);
    }

    public function test_saving_one_notification_preserves_other_rules_and_disabled_templates(): void
    {
        $settings = app(TelegramNotificationSettings::class);
        $settings->save(TelegramNotificationKind::OrderPaid, ['enabled' => false, 'template' => 'Paid :order_no', 'repeat_count' => 2]);
        $settings->save(TelegramNotificationKind::NoAvailablePaymentMethod, ['enabled' => true, 'template' => '', 'cooldown_minutes' => 3]);

        $this->assertSame(['enabled' => false, 'template' => 'Paid :order_no', 'repeat_count' => 2], $settings->get(TelegramNotificationKind::OrderPaid));
        $stored = SystemConfig::getArray(TelegramNotificationSettings::CONFIG_KEY);
        $this->assertSame(3, $stored['no_available_payment_method']['cooldown_minutes']);
        $this->assertSame(2, $stored['order_paid']['repeat_count']);
    }

    public function test_existing_saved_rules_use_default_order_information_without_overwriting_the_message(): void
    {
        $existing = ['enabled' => true, 'template' => 'Existing :order_no', 'repeat_count' => 2];
        SystemConfig::set(TelegramNotificationSettings::CONFIG_KEY, ['order_paid' => $existing]);

        $rule = app(TelegramNotificationSettings::class)->get(TelegramNotificationKind::OrderPaid);

        $this->assertStringStartsWith("Existing :order_no\n\n", $rule['template']);
        $this->assertStringContainsString(':order.transaction_id', $rule['template']);
        $this->assertSame($existing, SystemConfig::getArray(TelegramNotificationSettings::CONFIG_KEY)['order_paid']);
    }

    public function test_legacy_body_and_order_templates_are_combined_without_confusing_their_amounts(): void
    {
        $existing = ['enabled' => false, 'template' => 'USD :amount', 'order_template' => 'Original :currency :amount', 'cooldown_minutes' => 3];
        SystemConfig::set(TelegramNotificationSettings::CONFIG_KEY, ['no_available_payment_method' => $existing]);
        $kind = TelegramNotificationKind::NoAvailablePaymentMethod;
        $settings = app(TelegramNotificationSettings::class);
        $rule = $settings->get($kind);

        $this->assertSame("USD :amount\n\nOriginal :order.currency :order.amount", $rule['template']);
        $this->assertSame("USD 100.00\n\nOriginal EUR 87.65", app(TelegramMessageTemplate::class)->render(
            $kind, ['amount' => '100.00'], orderDetails: ['currency' => 'EUR', 'amount' => '87.65'],
        ));
        $this->assertSame($existing, SystemConfig::getArray(TelegramNotificationSettings::CONFIG_KEY)[$kind->value]);

        $settings->save($kind, $rule);

        $this->assertSame($rule, $settings->get($kind));
        $stored = SystemConfig::getArray(TelegramNotificationSettings::CONFIG_KEY)[$kind->value];
        $this->assertArrayNotHasKey('order_template', $stored);
        $this->assertSame($rule['template'], $stored['message_template']);
    }

    public function test_legacy_digest_templates_include_all_formerly_appended_content(): void
    {
        $this->app->setLocale('en');
        SystemConfig::set(TelegramNotificationSettings::CONFIG_KEY, ['shipment_sync_failed' => [
            'enabled' => true, 'template' => 'Failures :count', 'order_template' => 'TX :transaction_id',
        ]]);

        $template = app(TelegramNotificationSettings::class)->get(TelegramNotificationKind::ShipmentSyncFailed)['template'];

        $this->assertSame("Failures :count\n\n[orders]\nApplication: :shipment.application\nOrder: :order.order_no\nMerchant Order: :shipment.merchant_order_no\nTracking: :shipment.tracking_number\nReason: :shipment.error\nTX :order.transaction_id\n[/orders]", $template);
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_are_rejected_without_writing(TelegramNotificationKind $kind, array $overrides, string $field): void
    {
        $settings = app(TelegramNotificationSettings::class);

        try {
            $settings->save($kind, array_merge($settings->defaults($kind), $overrides));
            $this->fail('Invalid settings must be rejected');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }

        $this->assertDatabaseMissing('system_configs', ['config_key' => TelegramNotificationSettings::CONFIG_KEY]);
    }

    public static function invalidSettings(): array
    {
        return [
            'unsupported variable' => [TelegramNotificationKind::OrderPaid, ['template' => ':bot_token'], 'template'],
            'unsupported order variable' => [TelegramNotificationKind::OrderPaid, ['template' => ':order.bot_token'], 'template'],
            'long complete template' => [TelegramNotificationKind::OrderPaid, ['template' => str_repeat('a', 4001)], 'template'],
            'product sync has no order' => [TelegramNotificationKind::SiteProductsSyncCompleted, ['template' => ':order.order_no'], 'template'],
            'unclosed block' => [TelegramNotificationKind::UnshippedOverdue, ['template' => '[orders]:order.order_no'], 'template'],
            'empty block only' => [TelegramNotificationKind::UnshippedOverdue, ['template' => "[orders]\n[/orders]"], 'template'],
            'unopened block' => [TelegramNotificationKind::UnshippedOverdue, ['template' => ':order.order_no[/orders]'], 'template'],
            'nested block' => [TelegramNotificationKind::UnshippedOverdue, ['template' => '[orders][orders]:order.order_no[/orders][/orders]'], 'template'],
            'multiple blocks' => [TelegramNotificationKind::UnshippedOverdue, ['template' => '[orders]a[/orders][orders]b[/orders]'], 'template'],
            'reversed block' => [TelegramNotificationKind::UnshippedOverdue, ['template' => '[/orders]a[orders]'], 'template'],
            'single order cannot repeat' => [TelegramNotificationKind::OrderPaid, ['template' => '[orders]:order.order_no[/orders]'], 'template'],
            'order variable outside block' => [TelegramNotificationKind::UnshippedOverdue, ['template' => ':order.order_no[orders]a[/orders]'], 'template'],
            'shipment variable outside block' => [TelegramNotificationKind::ShipmentSyncFailed, ['template' => '[orders]a[/orders]:shipment.error'], 'template'],
            'old separate editor' => [TelegramNotificationKind::OrderPaid, ['order_template' => ':order_no'], 'enabled'],
            'unknown recipient setting' => [TelegramNotificationKind::OrderPaid, ['recipients' => ['super_admins']], 'enabled'],
            'empty enabled' => [TelegramNotificationKind::OrderPaid, ['enabled' => null], 'enabled'],
            'too many repeats' => [TelegramNotificationKind::OrderPaid, ['repeat_count' => 6], 'repeat_count'],
            'no repeats' => [TelegramNotificationKind::OrderPaid, ['repeat_count' => 0], 'repeat_count'],
            'fractional cooldown' => [TelegramNotificationKind::NoAvailablePaymentMethod, ['cooldown_minutes' => 1.5], 'cooldown_minutes'],
            'too early reminder' => [TelegramNotificationKind::OrderDisputeDueSoon, ['reminder_hours' => 721], 'reminder_hours'],
            'zero days' => [TelegramNotificationKind::UnshippedOverdue, ['unshipped_days' => 0], 'unshipped_days'],
            'invalid time' => [TelegramNotificationKind::UnshippedOverdue, ['send_time' => '24:00'], 'send_time'],
        ];
    }

    public function test_custom_templates_and_values_are_escaped_and_never_executed(): void
    {
        $settings = app(TelegramNotificationSettings::class);
        $settings->save(TelegramNotificationKind::OrderPaid, ['enabled' => true, 'template' => '<b>:order_no</b> {{ 1 + 1 }} :amount :currency', 'repeat_count' => 1]);

        $message = app(TelegramMessageTemplate::class)->render(TelegramNotificationKind::OrderPaid, [
            'order_no' => '<script>alert(1)</script>', 'amount' => '19.90', 'currency' => 'USD',
        ]);

        $this->assertStringContainsString('&lt;b&gt;&lt;script&gt;', $message);
        $this->assertStringContainsString('{{ 1 + 1 }} 19.90 USD', $message);
        $this->assertStringNotContainsString('<script>', $message);
    }

    public function test_every_notification_has_valid_defaults_in_both_languages(): void
    {
        $templates = app(TelegramMessageTemplate::class);

        foreach (['zh_CN', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (TelegramNotificationKind::cases() as $kind) {
                $default = $templates->defaultTemplate($kind);
                $this->assertStringNotContainsString('admin.', $default, $kind->value);
                TelegramMessageTemplate::validate($kind, $default);
                $rendered = implode("\n", $templates->preview($kind, ''));
                $this->assertDoesNotMatchRegularExpression('/:[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*/', $rendered, $kind->value);
                $this->assertStringNotContainsString('[orders]', $rendered, $kind->value);
            }
        }
    }
}

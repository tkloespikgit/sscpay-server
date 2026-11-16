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

        $this->assertSame(['enabled' => false, 'template' => 'Paid :order_no', 'order_template' => '', 'repeat_count' => 2], $settings->get(TelegramNotificationKind::OrderPaid));
        $stored = SystemConfig::getArray(TelegramNotificationSettings::CONFIG_KEY);
        $this->assertSame(3, $stored['no_available_payment_method']['cooldown_minutes']);
        $this->assertSame(2, $stored['order_paid']['repeat_count']);
    }

    public function test_existing_saved_rules_use_default_order_information_without_overwriting_the_message(): void
    {
        $existing = ['enabled' => true, 'template' => 'Existing :order_no', 'repeat_count' => 2];
        SystemConfig::set(TelegramNotificationSettings::CONFIG_KEY, ['order_paid' => $existing]);

        $rule = app(TelegramNotificationSettings::class)->get(TelegramNotificationKind::OrderPaid);

        $this->assertSame('', $rule['order_template']);
        $this->assertSame('Existing :order_no', $rule['template']);
        $this->assertSame($existing, SystemConfig::getArray(TelegramNotificationSettings::CONFIG_KEY)['order_paid']);
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
            'unsupported order variable' => [TelegramNotificationKind::OrderPaid, ['order_template' => ':bot_token'], 'order_template'],
            'event variable in order information' => [TelegramNotificationKind::OrderDisputeOpened, ['order_template' => ':event_no'], 'order_template'],
            'long order template' => [TelegramNotificationKind::OrderPaid, ['order_template' => str_repeat('a', 1001)], 'order_template'],
            'batch without order information' => [TelegramNotificationKind::SiteProductsSyncCompleted, ['order_template' => ':order_no'], 'enabled'],
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
                preg_match_all('/(?<![a-zA-Z0-9_]):([a-z][a-z0-9_]*)/', $default, $matches);
                $this->assertSame([], array_values(array_diff($matches[1], $kind->variables())), $kind->value);
                $rendered = $templates->render($kind, $templates->sampleVariables($kind));
                $this->assertDoesNotMatchRegularExpression('/(?<![a-zA-Z0-9_]):[a-z][a-z0-9_]*/', $rendered, $kind->value);
            }
        }
    }
}

<?php

namespace App\Services;

use App\Support\TelegramNotificationKind;

final readonly class TelegramMessageTemplate
{
    public function __construct(private TelegramNotificationSettings $settings) {}

    public function defaultTemplate(TelegramNotificationKind $kind): string
    {
        $key = $kind === TelegramNotificationKind::ShipmentSyncFailed
            ? 'admin.merchant_shipment_sync.telegram_heading'
            : 'admin.telegram_notification.'.$kind->value;

        return trim(str_replace(':list', '', __($key)));
    }

    /** @param array<string, scalar|null> $variables */
    public function render(TelegramNotificationKind $kind, array $variables, ?string $template = null): string
    {
        $template ??= (string) $this->settings->get($kind)['template'];
        $template = filled($template) ? $template : $this->defaultTemplate($kind);
        $replacements = [];

        foreach ($kind->variables() as $key) {
            $value = $variables[$key] ?? __('admin.telegram_notification.order_detail_unavailable');
            $replacements[':'.$key] = mb_substr((string) $value, 0, 200);
        }

        return htmlspecialchars(mb_substr(strtr($template, $replacements), 0, 3000), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array<string, string> */
    public function sampleVariables(TelegramNotificationKind $kind): array
    {
        $samples = [
            'order_no' => 'SYS-DEMO-001', 'merchant_order_no' => 'SHOP-DEMO-001',
            'event_no' => 'DISPUTE-DEMO-001', 'currency' => 'USD', 'amount' => '100.00',
            'converted_amount' => '100.00', 'gateway_amount' => '95.00', 'order_amount' => '100.00',
            'customer_email' => 'customer@example.com', 'domain' => 'shop.example.com',
            'gateway_host' => 'shop.example.com', 'method_code' => 'demo_channel',
            'payment_method' => 'demo_channel', 'method_name' => __('admin.telegram_routes.sample_channel'),
            'group_name' => __('admin.telegram_routes.sample_group'), 'group_key' => 'demo_group',
            'merchant_id' => '1', 'payment_method_id' => '1', 'source' => 'api', 'http_status' => '503',
            'pga_code' => 'DEMO_ERROR', 'file_name' => 'tracking-demo.csv', 'tracking_number' => 'DEMO123456',
            'due_at' => '2026-12-01 10:00:00', 'hours' => '240', 'count' => '2',
            'failed_times' => '5', 'max_failed_times' => '5', 'total' => '10', 'success' => '9',
            'failed' => '1', 'created' => '6', 'updated' => '4', 'deleted' => '0', 'skipped' => '0',
            'error' => __('admin.telegram_routes.sample_error'), 'reason' => __('admin.telegram_routes.sample_error'),
            'status' => __('admin.telegram_routes.sample_status'),
        ];

        return array_intersect_key($samples, array_flip($kind->variables()));
    }
}

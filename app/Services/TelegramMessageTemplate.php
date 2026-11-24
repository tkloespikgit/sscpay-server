<?php

namespace App\Services;

use App\Support\OrderTelegramSummary;
use App\Support\TelegramNotificationKind;
use Illuminate\Validation\ValidationException;

final readonly class TelegramMessageTemplate
{
    public const MAX_TEMPLATE_LENGTH = 4000;

    public const MAX_MESSAGE_LENGTH = 4000;

    private const VARIABLE_PATTERN = '/:([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)/';

    public function __construct(private TelegramNotificationSettings $settings) {}

    public static function defaultTemplate(TelegramNotificationKind $kind): string
    {
        return self::composeLegacyTemplate($kind);
    }

    public static function composeLegacyTemplate(TelegramNotificationKind $kind, string $body = '', string $orderTemplate = ''): string
    {
        $key = $kind === TelegramNotificationKind::ShipmentSyncFailed
            ? 'admin.merchant_shipment_sync.telegram_heading'
            : 'admin.telegram_notification.'.$kind->value;
        $body = filled($body) ? $body : trim(str_replace(':list', '', __($key)));

        if (! $kind->hasOrderSummary()) {
            return $body;
        }

        $orderTemplate = filled($orderTemplate) ? $orderTemplate : OrderTelegramSummary::defaultTemplate();
        $orderTemplate = preg_replace_callback(self::VARIABLE_PATTERN, fn (array $match): string => ':order.'.$match[1], $orderTemplate);

        if ($kind === TelegramNotificationKind::ShipmentSyncFailed) {
            $line = preg_replace_callback(self::VARIABLE_PATTERN, fn (array $match): string => $match[1] === 'order_no'
                ? ':order.order_no' : ':shipment.'.$match[1], __('admin.merchant_shipment_sync.telegram_line'));
            $orderTemplate = $line."\n".$orderTemplate;
        }

        return $body."\n\n".($kind->isOrderBatch() ? "[orders]\n{$orderTemplate}\n[/orders]" : $orderTemplate);
    }

    /** @return list<string> */
    public static function variables(TelegramNotificationKind $kind): array
    {
        return [
            ...$kind->variables(),
            ...($kind->hasOrderSummary() ? array_map(fn (string $key): string => 'order.'.$key, OrderTelegramSummary::variables()) : []),
            ...($kind === TelegramNotificationKind::ShipmentSyncFailed ? ['shipment.application', 'shipment.merchant_order_no', 'shipment.tracking_number', 'shipment.error'] : []),
        ];
    }

    /** @return array{string, string|null, string} */
    private static function parts(TelegramNotificationKind $kind, string $template): array
    {
        $opens = substr_count($template, '[orders]');
        $closes = substr_count($template, '[/orders]');
        $parts = [$template, null, ''];

        if ($opens !== 0 || $closes !== 0) {
            if (! $kind->isOrderBatch() || $opens !== 1 || $closes !== 1
                || ! preg_match('/\A(.*?)\[orders\](.*?)\[\/orders\](.*)\z/s', $template, $matches)) {
                throw ValidationException::withMessages(['template' => __('admin.telegram_routes.invalid_order_block')]);
            }

            $parts = [$matches[1], trim($matches[2], "\r\n"), $matches[3]];

            if (trim(implode('', $parts)) === '') {
                throw ValidationException::withMessages(['template' => __('admin.telegram_routes.empty_message')]);
            }
        }

        foreach ($parts as $index => $part) {
            preg_match_all(self::VARIABLE_PATTERN, $part ?? '', $matches);
            $allowed = $kind->isOrderBatch() && $index !== 1 ? $kind->variables() : self::variables($kind);

            if (array_diff($matches[1], $allowed) !== []) {
                throw ValidationException::withMessages(['template' => __('admin.telegram_routes.invalid_variables')]);
            }
        }

        return $parts;
    }

    public static function validate(TelegramNotificationKind $kind, string $template): void
    {
        self::parts($kind, $template);
    }

    public function content(TelegramNotificationKind $kind, ?string $template = null): string
    {
        $template ??= (string) $this->settings->get($kind)['template'];
        $template = trim($template);

        return filled($template) ? $template : self::defaultTemplate($kind);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, string>|null  $orderDetails
     */
    public function render(TelegramNotificationKind $kind, array $variables, ?string $template = null, ?array $orderDetails = null): string
    {
        $template = $this->content($kind, $template);
        self::validate($kind, $template);

        return $this->escapeLimited($this->substitute($kind, $template, $variables, $orderDetails ?? []), self::MAX_MESSAGE_LENGTH);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<int, array{order: array<string, string>, shipment?: array<string, string>}>  $rows
     * @return list<array{ids: list<int>, message: string}>
     */
    public function renderBatches(TelegramNotificationKind $kind, array $variables, array $rows, ?string $template = null): array
    {
        [$before, $repeat, $after] = self::parts($kind, $this->content($kind, $template));
        $limit = $kind === TelegramNotificationKind::ShipmentSyncFailed ? 5 : PHP_INT_MAX;

        if ($repeat === null) {
            $message = $this->render($kind, $variables, $template);

            return array_map(fn (array $ids): array => ['ids' => $ids, 'message' => $message], array_chunk(array_keys($rows), $limit));
        }

        $before = $this->substitute($kind, $before, $variables);
        $after = $this->substitute($kind, $after, $variables);
        $envelopeLimit = $this->messageLength(htmlspecialchars($before.$after, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) > 2000 ? 1000 : 2000;
        $before = $this->escapeLimited($before, $envelopeLimit);
        $after = $this->escapeLimited($after, $envelopeLimit);
        $rowLimit = self::MAX_MESSAGE_LENGTH - $this->messageLength($before.$after);
        $batches = [];
        $ids = [];
        $lines = '';

        foreach ($rows as $id => $row) {
            $line = $this->escapeLimited($this->substitute($kind, $repeat, $variables, $row['order'], $row['shipment'] ?? []), $rowLimit);
            $candidate = $ids === [] ? $line : $lines."\n\n".$line;

            if ($ids !== [] && (count($ids) >= $limit || $this->messageLength($candidate) > $rowLimit)) {
                $batches[] = ['ids' => $ids, 'message' => $before.$lines.$after];
                $ids = [];
                $candidate = $line;
            }

            $ids[] = $id;
            $lines = $candidate;
        }

        if ($ids !== []) {
            $batches[] = ['ids' => $ids, 'message' => $before.$lines.$after];
        }

        return $batches;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, string>  $order
     * @param  array<string, string>  $shipment
     */
    private function substitute(TelegramNotificationKind $kind, string $template, array $variables, array $order = [], array $shipment = []): string
    {
        $replacements = [];

        foreach ($kind->variables() as $key) {
            $value = $variables[$key] ?? __('admin.telegram_notification.order_detail_unavailable');
            $replacements[$key] = mb_substr((string) $value, 0, 200);
        }

        foreach (OrderTelegramSummary::variables() as $key) {
            $value = trim((string) ($order[$key] ?? ''));
            $replacements['order.'.$key] = mb_substr($value !== '' ? $value : __($key === 'paid_at'
                ? 'admin.telegram_notification.order_not_paid' : 'admin.telegram_notification.order_detail_unavailable'), 0, 100);
        }

        foreach (['application', 'merchant_order_no', 'tracking_number', 'error'] as $key) {
            $replacements['shipment.'.$key] = mb_substr((string) ($shipment[$key] ?? __('admin.telegram_notification.order_detail_unavailable')), 0, 50);
        }

        return preg_replace_callback(self::VARIABLE_PATTERN, fn (array $match): string => $replacements[$match[1]] ?? $match[0], $template);
    }

    private function escapeLimited(string $text, int $limit): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($this->messageLength($escaped) <= $limit) {
            return $escaped;
        }

        $result = '';
        $length = 0;

        foreach (mb_str_split($text) as $character) {
            $encoded = htmlspecialchars($character, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $length += $this->messageLength($encoded);

            if ($length > $limit - 1) {
                break;
            }

            $result .= $encoded;
        }

        return $result.'…';
    }

    private function messageLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** @return list<string> */
    public function preview(TelegramNotificationKind $kind, string $template, ?int $unshippedDays = null): array
    {
        $variables = $this->sampleVariables($kind);

        if ($kind === TelegramNotificationKind::UnshippedOverdue && $unshippedDays !== null) {
            $variables['hours'] = (string) ($unshippedDays * 24);
        }

        $order = [
            'order_no' => 'SYS-DEMO-001', 'merchant_order_no' => 'SHOP-DEMO-001', 'transaction_id' => 'TX-DEMO-001',
            'payment_method_name' => __('admin.telegram_routes.sample_channel'), 'paid_at' => '2026-11-16 10:00:00',
            'currency' => 'USD', 'amount' => '100.00',
        ];

        if (! $kind->isOrderBatch()) {
            return [$this->render($kind, $variables, $template, $order)];
        }

        $shipment = ['application' => 'Demo shop', 'merchant_order_no' => 'SHOP-DEMO-001', 'tracking_number' => 'DEMO123456', 'error' => __('admin.telegram_routes.sample_error')];

        return array_column($this->renderBatches($kind, $variables, [
            1 => ['order' => $order, 'shipment' => $shipment],
            2 => ['order' => array_replace($order, ['order_no' => 'SYS-DEMO-002', 'merchant_order_no' => 'SHOP-DEMO-002', 'transaction_id' => 'TX-DEMO-002']),
                'shipment' => array_replace($shipment, ['merchant_order_no' => 'SHOP-DEMO-002', 'tracking_number' => 'DEMO654321'])],
        ], $template), 'message');
    }

    /** @return array<string, string> */
    public function sampleVariables(TelegramNotificationKind $kind): array
    {
        $samples = [
            'order_no' => 'SYS-DEMO-001', 'merchant_order_no' => 'SHOP-DEMO-001',
            'event_no' => 'DISPUTE-DEMO-001', 'alert_id' => 'ALERT-DEMO-001', 'service' => 'Ethoca',
            'currency' => 'USD', 'amount' => '100.00',
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

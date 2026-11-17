<?php

namespace Tests\Feature;

use App\Services\TelegramMessageTemplate;
use App\Support\TelegramNotificationKind;
use Tests\TestCase;

class TelegramMessageTemplateTest extends TestCase
{
    public function test_complete_message_reorders_fields_without_appending_removed_details_or_replacing_values_recursively(): void
    {
        $message = app(TelegramMessageTemplate::class)->render(TelegramNotificationKind::OrderPaid,
            ['currency' => 'USD', 'amount' => '95.00'],
            " \n:order.transaction_id\n<b>Received :currency:amount</b>\n ",
            ['transaction_id' => 'TX<&>:amount', 'order_no' => 'REMOVED', 'amount' => '100.00'],
        );

        $this->assertSame("TX&lt;&amp;&gt;:amount\n&lt;b&gt;Received USD95.00&lt;/b&gt;", $message);
    }

    public function test_removed_order_block_leaves_only_the_requested_digest_and_retains_every_alert_id(): void
    {
        $rows = [];
        for ($id = 1; $id <= 6; $id++) {
            $rows[$id] = ['order' => ['order_no' => 'HIDDEN-'.$id]];
        }

        $batches = app(TelegramMessageTemplate::class)->renderBatches(TelegramNotificationKind::ShipmentSyncFailed, ['count' => 6], $rows, 'Review :count failures');

        $this->assertSame([
            ['ids' => [1, 2, 3, 4, 5], 'message' => 'Review 6 failures'],
            ['ids' => [6], 'message' => 'Review 6 failures'],
        ], $batches);
    }

    public function test_batch_splitting_preserves_each_order_once_with_the_edited_header_and_footer(): void
    {
        $rows = [];
        for ($id = 1; $id <= 7; $id++) {
            $rows[$id] = ['order' => ['order_no' => 'ORDER-'.$id], 'shipment' => ['tracking_number' => 'TRACK-'.$id]];
        }
        $template = "Header :count\n[orders]\n:order.order_no :shipment.tracking_number\n".str_repeat('"', 160)."\n[/orders]\nFooter";

        $batches = app(TelegramMessageTemplate::class)->renderBatches(TelegramNotificationKind::ShipmentSyncFailed, ['count' => 7], $rows, $template);

        $this->assertGreaterThan(1, count($batches));
        $this->assertSame(range(1, 7), array_merge(...array_column($batches, 'ids')));
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(5, count($batch['ids']));
            $this->assertLessThanOrEqual(4000, mb_strlen($batch['message']));
            $this->assertStringStartsWith("Header 7\n", $batch['message']);
            $this->assertStringEndsWith("\nFooter", $batch['message']);
            foreach ($batch['ids'] as $id) {
                $this->assertSame(1, substr_count($batch['message'], 'ORDER-'.$id.' TRACK-'.$id));
            }
        }
    }

    public function test_long_single_order_and_batch_envelope_are_shortened_without_breaking_entities_or_dropping_orders(): void
    {
        $templates = app(TelegramMessageTemplate::class);
        $single = $templates->render(TelegramNotificationKind::OrderPaid, [], str_repeat('"', 3500));

        $this->assertStringEndsWith('…', $single);
        $this->assertLessThanOrEqual(4000, mb_strlen($single));
        $this->assertMatchesRegularExpression('/\A(?:&quot;)+…\z/', $single);

        $batches = $templates->renderBatches(TelegramNotificationKind::UnshippedOverdue, [], [
            11 => ['order' => ['order_no' => 'A']], 12 => ['order' => ['order_no' => 'B']],
        ], str_repeat('&', 1000).'[orders]:order.order_no '.str_repeat('"', 1000).'[/orders]'.str_repeat('<', 1000));

        $this->assertCount(2, $batches);
        $this->assertSame([[11], [12]], array_column($batches, 'ids'));
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(4000, mb_strlen($batch['message']));
            $this->assertMatchesRegularExpression('/\A(?:&amp;)+…[AB] (?:&quot;)+…(?:&lt;)+…\z/', $batch['message']);
        }
    }

    public function test_long_emoji_messages_fit_the_same_limit_in_preview_and_delivery(): void
    {
        $template = str_repeat('😀', 2500);
        $templates = app(TelegramMessageTemplate::class);

        $message = $templates->render(TelegramNotificationKind::OrderPaid, [], $template);

        $this->assertSame(str_repeat('😀', 1999).'…', $message);
        $this->assertSame([$message], $templates->preview(TelegramNotificationKind::OrderPaid, $template));
    }

    public function test_digest_preview_uses_the_same_splitting_and_content_as_delivery(): void
    {
        $template = "Orders :count\n[orders]:order.order_no ".str_repeat('&', 500)."[/orders]\nEnd";
        $templates = app(TelegramMessageTemplate::class);

        $preview = $templates->preview(TelegramNotificationKind::UnshippedOverdue, $template);
        $sent = $templates->renderBatches(TelegramNotificationKind::UnshippedOverdue, ['count' => 2], [
            101 => ['order' => ['order_no' => 'SYS-DEMO-001']], 102 => ['order' => ['order_no' => 'SYS-DEMO-002']],
        ], $template);

        $this->assertCount(2, $preview);
        $this->assertSame(array_column($sent, 'message'), $preview);
        $this->assertStringContainsString('SYS-DEMO-001', $preview[0]);
        $this->assertStringContainsString('SYS-DEMO-002', $preview[1]);
    }
}

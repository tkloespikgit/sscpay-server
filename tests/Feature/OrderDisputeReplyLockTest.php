<?php

namespace Tests\Feature;

use App\Exceptions\BalanceOperationException;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\OrderDisputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderDisputeReplyLockTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_reply_keeps_the_dispute_funds_frozen_and_preserves_the_deadline(): void
    {
        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '200.00']);
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $order = $this->makeOrder('SSC-DISPUTE', 'M-DISPUTE', ['status' => 'paid']);
        $event = $this->open($order, $operator);
        $due = $event->due_at->toDateTimeString();

        $reply = app(OrderDisputeService::class)->reply($event, $operator, ['content' => '<p>物流已送达</p>']);

        $this->assertSame($event->id, $reply->order_dispute_event_id);
        $this->assertSame($this->merchant->id, $reply->merchant_id);
        $this->assertSame('processing', $event->refresh()->status);
        $this->assertSame($due, $event->due_at->toDateTimeString());
        $this->assertSame('200.00', $this->merchant->refresh()->balance);
        $this->assertSame('100.00', $this->merchant->frozen_balance);
    }

    public function test_stale_processing_event_cannot_receive_a_reply_after_it_is_closed(): void
    {
        $this->createMerchantAndApplication();
        $this->merchant->update(['balance' => '200.00']);
        $operator = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $order = $this->makeOrder('SSC-DISPUTE', 'M-DISPUTE', ['status' => 'paid']);
        $staleEvent = $this->open($order, $operator);
        app(OrderDisputeService::class)->closeManually($staleEvent->fresh(), $operator);

        try {
            app(OrderDisputeService::class)->reply($staleEvent, $operator, ['content' => '迟到的回复']);
            $this->fail('结束后的事件不可回复。');
        } catch (BalanceOperationException) {
            $this->assertDatabaseCount('order_dispute_event_replies', 0);
            $this->assertSame('0.00', $this->merchant->refresh()->frozen_balance);
            $this->assertSame('200.00', $this->merchant->balance);
        }
    }

    private function open(Order $order, User $operator): OrderDisputeEvent
    {
        return app(BalanceService::class)->freezeForDisputeEvent($order, $operator, [
            'event_no' => 'case-1', 'reason' => '订单争议', 'images' => [], 'final_action' => 'refund',
            'deadline_value' => 3, 'deadline_unit' => 'days', 'deadline_hours' => 72,
        ]);
    }
}

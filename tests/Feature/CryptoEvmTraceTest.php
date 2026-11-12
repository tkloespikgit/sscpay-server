<?php

namespace Tests\Feature;

use App\Models\CryptoWebhookReceipt;
use App\Services\CryptoReceiptVerificationService;
use App\Support\CryptoEvmTrace;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoHistory;
use Tests\TestCase;

class CryptoEvmTraceTest extends TestCase
{
    use CreatesCryptoHistory;
    use RefreshDatabase;

    public function test_paths_preserve_exact_value_and_skip_reverted_subtrees_delegate_value_and_self_transfers(): void
    {
        $root = $this->frame('CALL', '0x20000000000001');
        $root['calls'] = [
            $this->frame('CALL', '0x2'),
            [...$this->frame('CALL', '0x3'), 'error' => 'execution reverted', 'calls' => [$this->frame('SELFDESTRUCT', '0x4')]],
            [...$this->frame('DELEGATECALL', '0x5'), 'calls' => [$this->frame('CALL', '0x6')]],
            [...$this->frame('CALL', '0x7'), 'to' => '0x'.str_repeat('d', 40)],
            $this->frame('STATICCALL', '0x8'),
            $this->frame('CREATE2', '0x9'),
            $this->frame('SELFDESTRUCT', '0xa'),
        ];

        $transfers = CryptoEvmTrace::transfers($root);

        $this->assertSame(['native:0', 'trace:0', 'trace:2.0', 'trace:5', 'trace:6'], array_column($transfers, 'position'));
        $this->assertSame(['9007199254740993', '2', '6', '9', '10'], array_column($transfers, 'units'));
    }

    #[DataProvider('malformedFrames')]
    public function test_malformed_or_unbounded_traces_fail_closed(array $overrides): void
    {
        $trace = [...$this->frame('CALL', '0x1'), ...$overrides];
        $this->expectException(DomainException::class);

        CryptoEvmTrace::transfers($trace);
    }

    public static function malformedFrames(): array
    {
        return [
            'unknown type' => [['type' => 'REWARD']],
            'missing value' => [['value' => null]],
            'decimal value' => [['value' => '100']],
            'float value' => [['value' => 0.1]],
            'missing recipient' => [['to' => null]],
            'invalid from' => [['from' => 'wallet']],
            'non-list children' => [['calls' => ['child' => []]]],
            'invalid child' => [['calls' => [null]]],
        ];
    }

    public function test_very_deep_trace_does_not_silently_truncate_transfers(): void
    {
        $root = $this->frame('CALL', '0x1');
        for ($depth = 0; $depth < 26; $depth++) {
            $root = [...$this->frame('CALL', '0x0'), 'calls' => [$root]];
        }
        $this->expectException(DomainException::class);

        CryptoEvmTrace::transfers($root);
    }

    #[DataProvider('mismatchedRoots')]
    public function test_transaction_trace_must_match_verified_top_level_transaction(string $field, string $value): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $receipt = CryptoWebhookReceipt::factory()->create(['merchant_id' => $monitor->merchant_id,
            'crypto_payment_request_id' => $monitor->crypto_payment_request_id, 'crypto_address_monitor_id' => $monitor->id,
            'transaction_hash' => str_repeat('c', 64)]);
        $rpc['traces']['0x'.str_repeat('c', 64)][$field] = $value;
        $this->fakeHistoryRpc($rpc);

        $this->assertFalse(app(CryptoReceiptVerificationService::class)->process($receipt->merchant_id, $receipt->id));
        $this->assertDatabaseCount('crypto_chain_events', 0);
        $this->assertSame('verification_failed', $receipt->fresh()->last_error_code);
    }

    public static function mismatchedRoots(): array
    {
        return ['sender' => ['from', '0x'.str_repeat('e', 40)], 'recipient' => ['to', '0x'.str_repeat('e', 40)],
            'value' => ['value', '0x1'], 'type' => ['type', 'STATICCALL'], 'error' => ['error', 'execution reverted']];
    }

    public function test_reverted_internal_transfer_is_not_an_incoming_event(): void
    {
        [$monitor, $rpc] = $this->historyFixture();
        $rpc['blocks'][100]['transactions'] = [];
        $this->addHistoryTransfer($rpc, $monitor, 100, str_repeat('c', 64), $monitor->paymentRequest->due_units, true);
        $rpc['traces']['0x'.str_repeat('c', 64)]['calls'][0]['error'] = 'execution reverted';
        $receipt = CryptoWebhookReceipt::factory()->create(['merchant_id' => $monitor->merchant_id,
            'crypto_payment_request_id' => $monitor->crypto_payment_request_id, 'crypto_address_monitor_id' => $monitor->id,
            'transaction_hash' => str_repeat('c', 64)]);
        $this->fakeHistoryRpc($rpc);

        $this->assertTrue(app(CryptoReceiptVerificationService::class)->process($receipt->merchant_id, $receipt->id));
        $this->assertSame('ignored', $receipt->fresh()->status);
        $this->assertDatabaseCount('crypto_chain_events', 0);
    }

    private function frame(string $type, string $value): array
    {
        return ['type' => $type, 'from' => '0x'.str_repeat('d', 40), 'to' => '0x'.str_repeat('a', 40), 'value' => $value];
    }
}

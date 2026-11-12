<?php

namespace Tests\Feature;

use App\Exceptions\BalanceOperationException;
use App\Models\CryptoAddress;
use App\Models\CryptoChainEvent;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoPaymentResolution;
use App\Models\CryptoWebhookReceipt;
use App\Models\Order;
use App\Models\User;
use App\Services\CryptoBalanceService;
use App\Services\CryptoPaymentEvidenceService;
use App\Services\CryptoQuoteService;
use App\Services\CryptoSettlementService;
use App\Services\OrderPaymentStatusService;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoFinance;
use Tests\TestCase;

class CryptoPaymentReconciliationTest extends TestCase
{
    use CreatesCryptoFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCryptoReview();
        Queue::fake();
    }

    public static function paymentCases(): array
    {
        return [
            'late' => ['40800000000000000', true, '408000000000000', '40392000000000000'],
            'short' => ['20000000000000000', false, '200000000000000', '19800000000000000'],
            'excess' => ['50000000000000000', false, '500000000000000', '49500000000000000'],
            'one wei' => ['1', false, '1', '0'],
        ];
    }

    #[DataProvider('paymentCases')]
    public function test_manual_acceptance_uses_confirmed_actual_amounts_and_frozen_fee(string $gross, bool $late, string $fee, string $net): void
    {
        $request = $this->newReviewPayment($gross, $late);
        $before = $this->assetBalance->balance_units;
        $resolution = $this->reconcileCrypto($request);
        $this->assertSame('accept', $resolution->action);
        $this->assertSame($fee, $resolution->fee_after_units);
        $this->assertSame($net, $resolution->net_after_units);
        $this->assertSame(bcadd($before, $net, 0), $this->assetBalance->refresh()->balance_units);
        $this->assertNotNull($request->order->fresh()->paid_at);
        $this->assertSame('credited', $request->fresh()->status);
        $this->assertSame('100.00', $request->order->fresh()->converted_amount);
        $this->assertSame('0.00', $request->order->fresh()->settlement_amount);
        $this->assertSame('0.00', $this->merchant->fresh()->balance);
        $this->assertSame('credited', app(CryptoSettlementService::class)->settle($request->merchant_id, $request->id));
        $this->assertSame(2, CryptoLedgerEntry::query()->count());
        Http::assertNothingSent();
    }

    public function test_reorg_reverses_original_income_without_erasing_payment_or_voucher_and_can_be_restored(): void
    {
        $paid = $this->creditedRequest->order->paid_at;
        $this->chainEvent->update(['orphaned_at' => now(), 'confirmed_at' => null]);
        $reversal = $this->reconcileCrypto();
        $this->assertSame('0', $reversal->net_after_units);
        $this->assertSame('0', $this->assetBalance->refresh()->balance_units);
        $this->assertSame($this->creditedRequest->net_units, $this->income->fresh()->amount_units);
        $this->assertTrue($paid->equalTo($this->creditedRequest->order->fresh()->paid_at));
        $this->assertTrue(Order::query()->whereKey($this->creditedRequest->order_id)->paidEver()->exists());
        $this->chainEvent->update(['orphaned_at' => null, 'confirmed_at' => now(), 'block_hash' => str_repeat('d', 64)]);
        $this->assertSame('review', app(CryptoSettlementService::class)->settle($this->merchant->id, $this->creditedRequest->id));
        $this->reconcileCrypto();
        $this->assertSame($this->income->amount_units, $this->assetBalance->refresh()->balance_units);
        $this->assertSame(1, CryptoLedgerEntry::query()->count());
        $this->assertSame(2, CryptoPaymentResolution::query()->count());
    }

    public function test_post_withdrawal_reorg_records_debt_and_future_income_offsets_it(): void
    {
        $withdrawal = $this->requestCrypto(['amount' => '0.03', 'fee_limit' => '0']);
        $this->processCrypto($withdrawal, 'approve');
        $this->processCrypto($withdrawal, 'complete', changes: ['fee' => '0']);
        $this->chainEvent->update(['orphaned_at' => now(), 'confirmed_at' => null]);
        $this->reconcileCrypto();
        $this->assertSame('0', $this->assetBalance->refresh()->balance_units);
        $this->assertSame('30000000000000000', $this->assetBalance->debt_units);
        $this->assertSame('0', $this->assetBalance->availableUnits());
        try {
            $this->requestCrypto(['amount' => '0.0001']);
            $this->fail('Debt must block withdrawal.');
        } catch (BalanceOperationException $e) {
            $this->assertSame(__('crypto.review.errors.debt'), $e->getMessage());
        }
        $incoming = $this->newReviewPayment('50000000000000000');
        $this->reconcileCrypto($incoming);
        $this->assertSame('0', $this->assetBalance->refresh()->debt_units);
        $this->assertSame('19500000000000000', $this->assetBalance->balance_units);
        $this->assertSame('30000000000000000', $incoming->order->fresh()->cryptoIncome->debt_before_units);
        Http::assertNothingSent();
    }

    public function test_reorg_preserves_active_holds_and_cancel_offsets_debt_with_released_funds(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->chainEvent->update(['orphaned_at' => now(), 'confirmed_at' => null]);
        $this->reconcileCrypto();
        $this->assertSame('12000000000000000', $this->assetBalance->refresh()->balance_units);
        $this->assertSame('12000000000000000', $this->assetBalance->reserved_units);
        $this->assertSame('12000000000000000', $this->assetBalance->debt_units);
        $this->processCrypto($withdrawal, 'cancel');
        $this->assertSame('0', $this->assetBalance->refresh()->balance_units);
        $this->assertSame('0', $this->assetBalance->reserved_units);
        $this->assertSame('0', $this->assetBalance->debt_units);
        $event = $withdrawal->events()->where('action', 'cancel')->sole();
        $this->assertSame('12000000000000000', $event->debt_before_units);
        $this->assertSame('0', $event->debt_after_units);
    }

    public function test_idempotent_retry_and_repeated_evidence_cannot_double_credit(): void
    {
        $request = $this->newReviewPayment('50000000000000000');
        $key = (string) Str::uuid();
        $first = $this->reconcileCrypto($request, $key);
        $this->assertSame($first->id, $this->reconcileCrypto($request, $key)->id);
        $this->expectException(BalanceOperationException::class);
        $this->expectExceptionMessage(__('crypto.review.errors.unchanged'));
        $this->reconcileCrypto($request);
    }

    public function test_changed_evidence_rejects_stale_modal_and_freezes_resolved_withdrawals(): void
    {
        $request = $this->newReviewPayment('50000000000000000');
        $resolution = $this->reconcileCrypto($request);
        CryptoChainEvent::factory()->create(['merchant_id' => $this->merchant->id, 'crypto_payment_request_id' => $request->id,
            'amount_units' => '100', 'block_height' => 155, 'confirmed_at' => now(), 'verified_at' => now()]);
        try {
            $this->requestCrypto();
            $this->fail('New evidence must freeze withdrawals before the settlement worker runs.');
        } catch (BalanceOperationException $e) {
            $this->assertSame(__('crypto.finance.errors.review'), $e->getMessage());
        }
        $code = $this->cryptoCode($this->reviewer);
        $this->refreshCryptoHistory();
        $this->expectException(BalanceOperationException::class);
        $this->expectExceptionMessage(__('crypto.review.errors.changed'));
        app(CryptoBalanceService::class)->reconcileOrder($request->merchant_id, $request->order_id, $this->reviewer,
            (string) Str::uuid(), $resolution->evidence_hash, 'Stale form', $code);
    }

    public static function unsafeEvidence(): array
    {
        return [['stale'], ['unconfirmed'], ['unverified'], ['beyond_cursor'], ['before_creation'], ['missing_head']];
    }

    #[DataProvider('unsafeEvidence')]
    public function test_unsafe_evidence_never_changes_balance(string $kind): void
    {
        $request = $this->newReviewPayment('50000000000000000');
        $code = $this->cryptoCode($this->reviewer);
        $this->refreshCryptoHistory();
        match ($kind) {
            'stale' => $request->update(['history_checked_at' => now()->subMinutes(5)]),
            'missing_head' => $request->update(['history_to_hash' => null]),
            'unconfirmed' => $request->chainEvents()->first()->update(['confirmed_at' => null]),
            'unverified' => $request->chainEvents()->first()->update(['verified_at' => null]),
            'beyond_cursor' => $request->chainEvents()->first()->update(['block_height' => 201]),
            'before_creation' => $request->chainEvents()->first()->update(['block_time' => now()->subDay()]),
        };
        $hash = app(CryptoPaymentEvidenceService::class)->capture($request->order)['hash'];
        try {
            app(CryptoBalanceService::class)->reconcileOrder($request->merchant_id, $request->order_id, $this->reviewer,
                (string) Str::uuid(), $hash, 'Unsafe proof', $code);
            $this->fail('Unsafe evidence accepted');
        } catch (BalanceOperationException $e) {
            $this->assertSame(__('crypto.review.errors.evidence'), $e->getMessage());
        }
        $this->assertSame(0, CryptoPaymentResolution::query()->count());
        $this->assertSame($this->income->amount_units, $this->assetBalance->fresh()->balance_units);
    }

    public function test_merchant_cannot_reconcile_even_with_direct_permission(): void
    {
        $this->requester->givePermissionTo(Permissions::CRYPTO_PAYMENTS_RECONCILE);
        $this->expectException(AuthorizationException::class);
        app(CryptoBalanceService::class)->reconcileOrder($this->merchant->id, $this->creditedRequest->order_id,
            $this->requester, (string) Str::uuid(), str_repeat('a', 64), 'Unauthorized', $this->cryptoCode($this->requester));
    }

    public function test_current_ownership_and_permission_are_rechecked(): void
    {
        $this->merchant->update(['owner_id' => User::factory()->create()->id]);
        $this->expectException(AuthorizationException::class);
        $this->reconcileCrypto();
    }

    public function test_recheck_is_audited_without_financial_changes_and_does_not_replace_last_resolution(): void
    {
        $first = $this->reconcileCrypto();
        app(CryptoBalanceService::class)->recheckOrder($this->merchant->id, $this->creditedRequest->order_id,
            $this->reviewer, 'Verify again', $this->cryptoCode($this->reviewer));
        $this->assertSame($first->id, $this->creditedRequest->order->fresh()->latestCryptoResolution->id);
        $this->assertSame('review', $this->creditedRequest->fresh()->status);
        $this->assertNull($this->creditedRequest->fresh()->history_checked_at);
        $this->assertSame($this->income->amount_units, $this->assetBalance->fresh()->balance_units);
        $this->assertSame('recheck', CryptoPaymentResolution::query()->latest('id')->first()->action);
        $next = $this->reconcileCrypto();
        $this->assertSame($next->net_before_units, $next->net_after_units);
        $this->assertSame('credited', $this->creditedRequest->fresh()->status);
    }

    public function test_expired_order_can_explicitly_accept_late_payment(): void
    {
        $request = $this->newReviewPayment('40800000000000000', true);
        $request->order->update(['status' => 'expired']);
        $this->reconcileCrypto($request);
        $this->assertNotNull($request->order->fresh()->paid_at);
        $this->assertSame('paid', $request->order->fresh()->status);
    }

    public function test_all_quote_addresses_are_reconciled_together_with_one_income_voucher(): void
    {
        $first = $this->newReviewPayment('20000000000000000', true);
        $address = CryptoAddress::factory()->create([
            'merchant_id' => $this->merchant->id, 'order_id' => $first->order_id,
            'payment_method_id' => $first->payment_method_id, 'address' => '0x'.str_repeat('d', 40), 'scan_from_block' => 100,
        ]);
        $second = CryptoPaymentRequest::factory()->create([
            ...$first->only(['merchant_id', 'order_id', 'payment_method_id', 'network', 'asset', 'decimals', 'quote_snapshot', 'due_units', 'fee_units', 'net_units']),
            'crypto_address_id' => $address->id, 'replaces_request_id' => $first->id,
        ]);
        CryptoChainEvent::factory()->create(['merchant_id' => $this->merchant->id, 'crypto_payment_request_id' => $second->id,
            'amount_units' => '30000000000000000', 'block_height' => 160, 'verified_at' => now(), 'confirmed_at' => now()]);
        $resolution = $this->reconcileCrypto($first);
        $this->assertSame('50000000000000000', $resolution->gross_after_units);
        $this->assertSame('49500000000000000', $resolution->net_after_units);
        $this->assertSame(1, CryptoLedgerEntry::query()->where('order_id', $first->order_id)->count());
        $this->assertSame('resolved', $second->fresh()->status);
        $this->assertSame('credited', app(CryptoSettlementService::class)->settle($second->merchant_id, $second->id));
    }

    public function test_bad_totp_and_revoked_permission_cannot_reconcile(): void
    {
        $request = $this->newReviewPayment('50000000000000000');
        try {
            app(CryptoBalanceService::class)->reconcileOrder($this->merchant->id, $request->order_id, $this->reviewer,
                (string) Str::uuid(), str_repeat('a', 64), 'Bad code', 'invalid');
            $this->fail('TOTP required');
        } catch (BalanceOperationException) {
            $this->assertSame(0, CryptoPaymentResolution::query()->count());
        }
        User::findOrFail($this->reviewer->id)->revokePermissionTo(Permissions::CRYPTO_PAYMENTS_RECONCILE);
        $this->expectException(AuthorizationException::class);
        $this->reconcileCrypto($request);
    }

    public function test_unclassified_review_receipt_cannot_be_manually_accepted(): void
    {
        $request = $this->creditedRequest;
        CryptoWebhookReceipt::factory()->create([
            'merchant_id' => $request->merchant_id, 'crypto_payment_request_id' => $request->id,
            'crypto_address_monitor_id' => $request->current_monitor_id, 'status' => 'review', 'last_error_code' => 'wrong_network',
        ]);
        $this->expectException(BalanceOperationException::class);
        $this->expectExceptionMessage(__('crypto.review.errors.evidence'));
        $this->reconcileCrypto();
    }

    public function test_fixed_fee_is_once_per_cumulative_payment_and_zero_on_full_reversal(): void
    {
        $quote = app(CryptoQuoteService::class)->calculate('ethereum-sepolia:ETH', '100', 'USD', '1', '2500', '2', '1', '2');
        $this->assertSame('1300000000000000', app(CryptoQuoteService::class)->feeForGross('50000000000000000', $quote));
        $this->assertSame('1500000000000000', app(CryptoQuoteService::class)->feeForGross('70000000000000000', $quote));
        $this->assertSame('0', app(CryptoQuoteService::class)->feeForGross('0', $quote));
    }

    public function test_automatic_income_offsets_existing_debt_with_an_audit_snapshot(): void
    {
        $this->assetBalance->update(['balance_units' => '0', 'debt_units' => '10000000000000000']);
        $request = $this->newReviewPayment('40800000000000000');
        $request->update(['status' => 'confirmed', 'review_reason' => null]);
        $entry = app(CryptoBalanceService::class)->creditConfirmedRequest($request->merchant_id, $request->id);
        $this->assertSame('40392000000000000', $entry->amount_units);
        $this->assertSame('30392000000000000', $entry->balance_after_units);
        $this->assertSame('10000000000000000', $entry->debt_before_units);
        $this->assertSame('0', $entry->debt_after_units);
    }

    public function test_duplicate_verified_receipts_do_not_invalidate_an_accepted_economic_snapshot(): void
    {
        $request = $this->creditedRequest;
        $data = ['merchant_id' => $request->merchant_id, 'crypto_payment_request_id' => $request->id,
            'crypto_address_monitor_id' => $request->current_monitor_id, 'status' => 'verified',
            'transaction_hash' => $this->chainEvent->transaction_hash];
        CryptoWebhookReceipt::factory()->create($data);
        $resolution = $this->reconcileCrypto();
        CryptoWebhookReceipt::factory()->create($data);
        $hash = app(CryptoPaymentEvidenceService::class)->capture($request->order)['hash'];
        $this->assertSame($resolution->evidence_hash, $hash);
        $this->assertSame('credited', app(CryptoSettlementService::class)->settle($request->merchant_id, $request->id));
    }

    public function test_audit_records_cannot_be_edited(): void
    {
        $resolution = $this->reconcileCrypto();
        $this->expectException(\LogicException::class);
        $resolution->update(['reason' => 'Erase history']);
    }

    public function test_failed_order_transition_rolls_back_manual_income_and_resolution(): void
    {
        $request = $this->newReviewPayment('50000000000000000');
        $this->mock(OrderPaymentStatusService::class)->shouldReceive('confirmCryptoPayment')->once()->andThrow(new \RuntimeException('Injected transition failure'));
        try {
            $this->reconcileCrypto($request);
            $this->fail('Expected transition failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected transition failure', $e->getMessage());
        }
        $this->assertSame(0, CryptoPaymentResolution::query()->count());
        $this->assertSame(1, CryptoLedgerEntry::query()->count());
        $this->assertSame($this->income->amount_units, $this->assetBalance->fresh()->balance_units);
        $this->assertNull($request->order->fresh()->paid_at);
    }
}

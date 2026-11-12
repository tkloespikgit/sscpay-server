<?php

namespace Tests\Concerns;

use App\Models\CryptoAddress;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoChainEvent;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoPaymentResolution;
use App\Models\CryptoWithdrawal;
use App\Models\User;
use App\Services\CryptoBalanceService;
use App\Services\CryptoPaymentEvidenceService;
use App\Support\Permissions;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;

trait CreatesCryptoFinance
{
    use CreatesCryptoMonitor;

    protected CryptoAssetBalance $assetBalance;

    protected CryptoPaymentRequest $creditedRequest;

    protected CryptoLedgerEntry $income;

    protected CryptoChainEvent $chainEvent;

    protected User $requester;

    protected User $reviewer;

    protected function createCryptoFinance(): void
    {
        $clock = \Mockery::mock(Google2FA::class)->makePartial();
        $clock->shouldReceive('getTimestamp')->andReturnUsing(fn (): int => intdiv(now()->getTimestamp(), 30));
        $this->app->instance(Google2FA::class, $clock);
        $monitor = $this->createCryptoMonitor();
        $this->creditedRequest = $monitor->paymentRequest;
        $this->creditedRequest->update(['status' => 'credited', 'credited_at' => now()]);
        $this->assetBalance = CryptoAssetBalance::factory()->create([
            'merchant_id' => $this->merchant->id, 'balance_units' => $this->creditedRequest->net_units,
        ]);
        $this->income = CryptoLedgerEntry::factory()->create([
            'merchant_id' => $this->merchant->id, 'order_id' => $this->creditedRequest->order_id,
            'crypto_payment_request_id' => $this->creditedRequest->id, 'crypto_asset_balance_id' => $this->assetBalance->id,
            'gross_units' => $this->creditedRequest->due_units, 'fee_units' => $this->creditedRequest->fee_units,
            'amount_units' => $this->creditedRequest->net_units, 'balance_before_units' => '0',
            'balance_after_units' => $this->creditedRequest->net_units, 'fee_snapshot' => $this->creditedRequest->quote_snapshot,
        ]);
        $this->chainEvent = CryptoChainEvent::factory()->create([
            'merchant_id' => $this->merchant->id, 'crypto_payment_request_id' => $this->creditedRequest->id,
            'amount_units' => $this->creditedRequest->due_units, 'verified_at' => now(), 'confirmed_at' => now(), 'block_height' => 100,
        ]);
        $this->requester = $this->cryptoUser($this->merchant->id);
        $this->reviewer = $this->cryptoUser(null);
        $this->merchant->update(['owner_id' => $this->reviewer->id]);
        Http::preventStrayRequests();
    }

    protected function cryptoUser(?int $merchantId, bool $superAdmin = false): User
    {
        $user = User::factory()->create(['merchant_id' => $merchantId, 'status' => true, 'is_super_admin' => $superAdmin]);
        $user->givePermissionTo([
            Permissions::CRYPTO_FINANCE_VIEW, Permissions::CRYPTO_WITHDRAWALS_REQUEST,
            Permissions::CRYPTO_WITHDRAWALS_REVIEW, Permissions::CRYPTO_WITHDRAWALS_CONFIRM,
        ]);
        $user->saveAppAuthenticationSecret(app(AppAuthentication::class)->generateSecret());

        return $user;
    }

    protected function cryptoCode(User $user): string
    {
        $this->travel(31)->seconds();

        return app(AppAuthentication::class)->getCurrentCode($user, $user->getAppAuthenticationSecret());
    }

    protected function requestCrypto(array $changes = [], ?User $user = null): CryptoWithdrawal
    {
        $user ??= $this->requester;
        $data = [
            'merchant_id' => $this->assetBalance->merchant_id, 'balance_id' => $this->assetBalance->id,
            'key' => (string) Str::uuid(), 'amount' => '0.01', 'fee_limit' => '0.002',
            'address' => '0x'.str_repeat('b', 40), 'remark' => 'Offline withdrawal', ...$changes,
        ];

        return app(CryptoBalanceService::class)->requestWithdrawal(
            $data['merchant_id'], $data['balance_id'], $user, $data['key'], $data['amount'], $data['fee_limit'],
            $data['address'], $data['remark'], $this->cryptoCode($user),
        );
    }

    protected function processCrypto(CryptoWithdrawal $withdrawal, string $action, ?User $user = null, array $changes = []): CryptoWithdrawal
    {
        $user ??= $action === 'cancel' ? $this->requester : $this->reviewer;

        return app(CryptoBalanceService::class)->processWithdrawal(
            $withdrawal->merchant_id, $withdrawal->id, $user, $action, $changes['reason'] ?? 'Offline records checked',
            $this->cryptoCode($user), $changes['fee'] ?? '0.001', $changes['reference'] ?? 'OFFLINE-2026-001',
            $changes['no_external_payment'] ?? false,
        );
    }

    protected function prepareCryptoReview(): void
    {
        $this->createCryptoFinance();
        $this->reviewer->givePermissionTo(Permissions::CRYPTO_PAYMENTS_RECONCILE);
        $this->creditedRequest->order->update(['status' => 'paid', 'normal_status' => 'paid', 'paid_at' => now()]);
        config(['services.tatum.history_max_head_age_seconds' => 300, 'services.tatum.verification_enabled' => true, 'services.tatum.settlement_enabled' => true]);
        $this->refreshCryptoHistory();
    }

    protected function refreshCryptoHistory(): void
    {
        foreach (CryptoPaymentRequest::query()->forMerchant($this->merchant->id)->get() as $request) {
            $request->update(['history_to_block' => 200, 'history_to_hash' => str_repeat('c', 64),
                'history_to_time' => now(), 'history_checked_at' => now(), 'history_error_code' => null]);
        }
    }

    protected function newReviewPayment(string $gross, bool $late = false): CryptoPaymentRequest
    {
        $order = $this->makeOrder((string) Str::uuid(), (string) Str::uuid(), [
            'payment_type' => 'crypto', 'payment_method_id' => $this->creditedRequest->payment_method_id,
            'settlement_amount' => '0', 'converted_amount' => '100',
        ]);
        $address = CryptoAddress::factory()->create([
            'merchant_id' => $this->merchant->id, 'order_id' => $order->id,
            'payment_method_id' => $this->creditedRequest->payment_method_id,
            'address' => '0x'.substr(hash('sha256', $order->id), 0, 40), 'scan_from_block' => 100,
        ]);
        $request = CryptoPaymentRequest::factory()->create([
            ...$this->creditedRequest->only(['merchant_id', 'payment_method_id', 'network', 'asset', 'decimals', 'quote_snapshot', 'due_units', 'fee_units', 'net_units']),
            'order_id' => $order->id, 'crypto_address_id' => $address->id,
            'status' => 'review', 'review_reason' => $late ? 'late_or_mismatched_transfer' : 'overpayment',
            'created_at' => now()->subMinutes(10), 'expires_at' => $late ? now()->subMinutes(5) : now()->addMinutes(5),
        ]);
        CryptoChainEvent::factory()->create([
            'merchant_id' => $this->merchant->id, 'crypto_payment_request_id' => $request->id,
            'amount_units' => $gross, 'verified_at' => now(), 'confirmed_at' => now(), 'block_height' => 150,
        ]);
        $this->refreshCryptoHistory();

        return $request;
    }

    protected function reconcileCrypto(?CryptoPaymentRequest $request = null, ?string $key = null): CryptoPaymentResolution
    {
        $request ??= $this->creditedRequest;
        $code = $this->cryptoCode($this->reviewer);
        $this->refreshCryptoHistory();
        $hash = app(CryptoPaymentEvidenceService::class)->capture($request->order)['hash'];

        return app(CryptoBalanceService::class)->reconcileOrder($request->merchant_id, $request->order_id,
            $this->reviewer, $key ?? (string) Str::uuid(), $hash, 'Merchant confirmed the evidence', $code);
    }
}

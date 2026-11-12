<?php

namespace App\Services;

use App\Exceptions\BalanceOperationException;
use App\Filament\Support\FinanceSecurity;
use App\Models\CryptoAddress;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoChainEvent;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoPaymentResolution;
use App\Models\CryptoWebhookReceipt;
use App\Models\CryptoWithdrawal;
use App\Models\CryptoWithdrawalEvent;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Support\CryptoAmount;
use App\Support\CryptoAssetRegistry;
use App\Support\CryptoCheckoutGuard;
use App\Support\CryptoFinanceAccess;
use App\Support\CryptoWithdrawalInput;
use App\Support\Permissions;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All crypto balance mutations use this service; fiat BalanceService is never called. */
final class CryptoBalanceService
{
    public function __construct(
        private readonly CryptoQuoteService $quotes,
        private readonly CryptoAssetRegistry $assets,
        private readonly CryptoPaymentEvidenceService $evidence,
    ) {}

    public function requestWithdrawal(
        int $merchantId, int $balanceId, User $operator, string $requestKey, string $amount,
        string $feeLimit, string $address, ?string $remark, ?string $mfaCode,
    ): CryptoWithdrawal {
        $this->verifyOperator($operator, $mfaCode);

        return DB::transaction(function () use ($merchantId, $balanceId, $operator, $requestKey, $amount, $feeLimit, $address, $remark): CryptoWithdrawal {
            $merchant = Merchant::query()->lockForUpdate()->findOrFail($merchantId);
            $actor = $this->authorizeOperator($operator, $merchant, Permissions::CRYPTO_WITHDRAWALS_REQUEST);
            if (! $merchant->status || ! Str::isUuid($requestKey)) {
                throw new BalanceOperationException(__('crypto.finance.errors.request'));
            }
            $balance = CryptoAssetBalance::query()->forMerchant($merchantId)->lockForUpdate()->findOrFail($balanceId);
            $decimals = $this->assets->get($balance->network.':'.$balance->asset)['decimals'];
            $amountUnits = CryptoWithdrawalInput::quantity($amount, $decimals, positive: true);
            $feeUnits = CryptoWithdrawalInput::quantity($feeLimit, $decimals);
            $destination = CryptoWithdrawalInput::address($balance->network, $address);
            $note = CryptoWithdrawalInput::text($remark, 500);
            $key = strtolower($requestKey);
            $existing = CryptoWithdrawal::query()->forMerchant($merchantId)->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->crypto_asset_balance_id !== $balanceId || (int) $existing->requested_by !== (int) $actor->id
                    || $existing->amount_units !== $amountUnits || $existing->fee_limit_units !== $feeUnits
                    || $existing->destination_address !== $destination || $existing->remark !== $note) {
                    throw new BalanceOperationException(__('crypto.finance.errors.key_conflict'));
                }

                return $existing;
            }
            $this->assertWithdrawable($balance);
            $hold = bcadd($amountUnits, $feeUnits, 0);
            if (bccomp($balance->availableUnits(), $hold, 0) < 0) {
                throw new BalanceOperationException(__('crypto.finance.errors.insufficient'));
            }
            $before = $balance->only(['balance_units', 'reserved_units', 'debt_units']);
            $balance->update(['reserved_units' => CryptoAmount::units(bcadd($balance->reserved_units, $hold, 0))]);
            $withdrawal = CryptoWithdrawal::query()->create([
                'merchant_id' => $merchantId, 'crypto_asset_balance_id' => $balanceId, 'request_key' => $key,
                'network' => $balance->network, 'asset' => $balance->asset, 'decimals' => $decimals,
                'amount_units' => $amountUnits, 'fee_limit_units' => $feeUnits, 'reserved_units' => $hold,
                'destination_address' => $destination, 'status' => 'pending', 'remark' => $note, 'requested_by' => $actor->id,
            ]);
            $this->writeWithdrawalEvent($withdrawal, $balance, $actor, 'request', null, $before, $note);

            return $withdrawal;
        }, 3);
    }

    /** These actions change internal accounting only. They never sign, broadcast or verify a payout. */
    public function processWithdrawal(
        int $merchantId, int $withdrawalId, User $operator, string $action, string $reason, ?string $mfaCode,
        ?string $actualFee = null, ?string $manualReference = null, bool $noExternalPayment = false,
    ): CryptoWithdrawal {
        $permission = match ($action) {
            'cancel' => Permissions::CRYPTO_WITHDRAWALS_REQUEST,
            'approve', 'reject' => Permissions::CRYPTO_WITHDRAWALS_REVIEW,
            'complete' => Permissions::CRYPTO_WITHDRAWALS_CONFIRM,
            default => throw new AuthorizationException,
        };
        $this->verifyOperator($operator, $mfaCode);

        return DB::transaction(function () use ($merchantId, $withdrawalId, $operator, $action, $permission, $reason, $actualFee, $manualReference, $noExternalPayment): CryptoWithdrawal {
            $merchant = Merchant::query()->lockForUpdate()->findOrFail($merchantId);
            $actor = $this->authorizeOperator($operator, $merchant, $permission, platformOnly: $action !== 'cancel');
            $withdrawal = CryptoWithdrawal::query()->forMerchant($merchantId)->lockForUpdate()->findOrFail($withdrawalId);
            if ($action === 'cancel' && (int) $withdrawal->requested_by !== (int) $actor->id) {
                throw new AuthorizationException;
            }
            $permittedStatuses = match ($action) {
                'complete' => ['approved'],
                'reject' => ['pending', 'approved'],
                default => ['pending'],
            };
            if (! in_array($withdrawal->status, $permittedStatuses, true)) {
                throw new BalanceOperationException(__('crypto.finance.errors.state'));
            }
            if ($action === 'reject' && $withdrawal->status === 'approved' && ! $noExternalPayment) {
                throw new BalanceOperationException(__('crypto.finance.errors.no_external_payment'));
            }
            $note = CryptoWithdrawalInput::text($reason, 500, required: true);
            $balance = CryptoAssetBalance::query()->forMerchant($merchantId)
                ->where('network', $withdrawal->network)->where('asset', $withdrawal->asset)
                ->lockForUpdate()->findOrFail($withdrawal->crypto_asset_balance_id);
            $this->assertBalance($balance);
            $hold = CryptoAmount::units($withdrawal->reserved_units);
            if ($hold !== bcadd(CryptoAmount::units($withdrawal->amount_units), CryptoAmount::units($withdrawal->fee_limit_units), 0)
                || bccomp($balance->reserved_units, $hold, 0) < 0) {
                throw new BalanceOperationException(__('crypto.finance.errors.hold'));
            }
            if (in_array($action, ['approve', 'complete'], true)) {
                if (! $merchant->status) {
                    throw new BalanceOperationException(__('crypto.finance.errors.request'));
                }
                $this->assertWithdrawable($balance);
            }
            $before = $balance->only(['balance_units', 'reserved_units', 'debt_units']);
            $from = $withdrawal->status;
            $to = match ($action) {
                'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled', 'complete' => 'completed',
            };
            $changes = ['status' => $to];
            if ($action === 'complete') {
                $fee = CryptoWithdrawalInput::quantity($actualFee ?? '', $withdrawal->decimals);
                if (bccomp($fee, $withdrawal->fee_limit_units, 0) > 0) {
                    throw new BalanceOperationException(__('crypto.finance.errors.fee_limit'));
                }
                $reference = CryptoWithdrawalInput::text($manualReference, 128, required: true);
                $deduction = CryptoAmount::units(bcadd($withdrawal->amount_units, $fee, 0));
                $balance->balance_units = CryptoAmount::units(bcsub($balance->balance_units, $deduction, 0));
                $changes += ['actual_fee_units' => $fee, 'completed_by' => $actor->id, 'completed_at' => now(),
                    'manual_reference' => $reference, 'completion_remark' => $note];
            } else {
                $changes += ['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_remark' => $note];
            }
            if ($action !== 'approve') {
                $balance->reserved_units = CryptoAmount::units(bcsub($balance->reserved_units, $hold, 0));
            }
            $this->applyDelta($balance, '0');
            $withdrawal->update($changes);
            $this->writeWithdrawalEvent($withdrawal, $balance, $actor, $action, $from, $before, $note);

            return $withdrawal;
        }, 3);
    }

    private function verifyOperator(User $operator, ?string $code): void
    {
        $fresh = User::query()->where('status', true)->find($operator->id);
        if (! $fresh || ! $fresh->can(Permissions::CRYPTO_FINANCE_VIEW)) {
            throw new AuthorizationException;
        }
        FinanceSecurity::assertVerified($fresh, $code);
    }

    private function authorizeOperator(User $operator, Merchant $merchant, string $permission, bool $platformOnly = false): User
    {
        $fresh = User::query()->where('status', true)->find($operator->id);
        if (! $fresh) {
            throw new AuthorizationException;
        }
        CryptoFinanceAccess::authorize($fresh, $merchant, $permission, $platformOnly);

        return $fresh;
    }

    private function assertBalance(CryptoAssetBalance $balance): void
    {
        if (bccomp(CryptoAmount::units($balance->balance_units), CryptoAmount::units($balance->reserved_units), 0) < 0) {
            throw new BalanceOperationException(__('crypto.finance.errors.hold'));
        }
    }

    private function assertWithdrawable(CryptoAssetBalance $balance): void
    {
        $this->assertBalance($balance);
        if (bccomp($balance->debt_units ?? '0', '0', 0) > 0) {
            throw new BalanceOperationException(__('crypto.review.errors.debt'));
        }
        $resolved = CryptoLedgerEntry::query()->forMerchant($balance->merchant_id)->where('crypto_asset_balance_id', $balance->id)
            ->whereHas('order.latestCryptoResolution')->with(['order.latestCryptoResolution', 'order.cryptoRequests'])->get();
        foreach ($resolved as $entry) {
            $order = $entry->order;
            if ($order->cryptoRequests->contains(fn ($r) => $r->review_reason !== null || $r->status === 'review')
                || $this->evidence->capture($order)['hash'] !== $order->latestCryptoResolution->evidence_hash) {
                throw new BalanceOperationException(__('crypto.finance.errors.review'));
            }
        }
        if (CryptoLedgerEntry::query()->forMerchant($balance->merchant_id)->where('crypto_asset_balance_id', $balance->id)
            ->whereDoesntHave('order.latestCryptoResolution')
            ->whereHas('cryptoPaymentRequest', fn ($query) => $query->forMerchant($balance->merchant_id)
                ->where(fn ($requests) => $requests->where('status', '!=', 'credited')
                    ->orWhereNotNull('review_reason')
                    ->orWhereHas('chainEvents', fn ($events) => $events->forMerchant($balance->merchant_id)
                        ->where(fn ($state) => $state->whereNotNull('orphaned_at')->orWhereNull('verified_at')->orWhereNull('confirmed_at')))))->exists()) {
            throw new BalanceOperationException(__('crypto.finance.errors.review'));
        }
    }

    /** @param array{balance_units: string, reserved_units: string} $before */
    private function writeWithdrawalEvent(
        CryptoWithdrawal $withdrawal, CryptoAssetBalance $balance, User $actor,
        string $action, ?string $from, array $before, ?string $reason,
    ): void {
        CryptoWithdrawalEvent::query()->create([
            'merchant_id' => $withdrawal->merchant_id, 'crypto_withdrawal_id' => $withdrawal->id,
            'crypto_asset_balance_id' => $balance->id, 'operator_id' => $actor->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $withdrawal->status,
            'balance_before_units' => $before['balance_units'], 'balance_after_units' => $balance->balance_units,
            'debt_before_units' => $before['debt_units'] ?? '0', 'debt_after_units' => $balance->debt_units,
            'reserved_before_units' => $before['reserved_units'], 'reserved_after_units' => $balance->reserved_units,
            'reason' => $reason, 'manual_reference' => $action === 'complete' ? $withdrawal->manual_reference : null,
        ]);
    }

    /** Queue another read of chain evidence without approving the funds or changing payment facts. */
    public function recheckOrder(int $merchantId, int $orderId, User $operator, string $reason, ?string $mfaCode): void
    {
        $this->verifyOperator($operator, $mfaCode);
        DB::transaction(function () use ($merchantId, $orderId, $operator, $reason): void {
            $merchant = Merchant::query()->lockForUpdate()->findOrFail($merchantId);
            $actor = $this->authorizeOperator($operator, $merchant, Permissions::CRYPTO_PAYMENTS_RECONCILE, platformOnly: true);
            $order = Order::query()->withoutGlobalScope(MerchantScope::class)
                ->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId);
            $note = CryptoWithdrawalInput::text($reason, 500, required: true);
            $proof = $this->evidence->capture($order);
            $root = $proof['request'];
            $income = $order->cryptoIncome;
            $previous = $order->latestCryptoResolution;
            $balance = $income ? CryptoAssetBalance::query()->forMerchant($merchantId)->findOrFail($income->crypto_asset_balance_id) : null;
            $data = ['merchant_id' => $merchantId, 'order_id' => $orderId, 'crypto_payment_request_id' => $root->id,
                'crypto_asset_balance_id' => $balance?->id, 'operator_id' => $actor->id, 'request_key' => (string) Str::uuid(),
                'network' => $root->network, 'asset' => $root->asset, 'action' => 'recheck', 'evidence_hash' => $proof['hash'],
                'evidence_snapshot' => $proof['snapshot'], 'fee_snapshot' => $root->quote_snapshot, 'reason' => $note,
                'reserved_units' => $balance?->reserved_units ?? '0'];
            foreach (['gross' => $previous?->gross_after_units ?? $income?->gross_units ?? '0',
                'fee' => $previous?->fee_after_units ?? $income?->fee_units ?? '0',
                'net' => $previous?->net_after_units ?? $income?->amount_units ?? '0',
                'balance' => $balance?->balance_units ?? '0', 'debt' => $balance?->debt_units ?? '0'] as $field => $value) {
                $data[$field.'_before_units'] = $data[$field.'_after_units'] = $value;
            }
            CryptoPaymentResolution::query()->create($data);
            foreach (CryptoCheckoutGuard::requests($order) as $request) {
                $request->update(['status' => 'review', 'review_reason' => 'manual_recheck', 'history_checked_at' => null,
                    'history_next_attempt_at' => now(), 'settlement_next_attempt_at' => now()]);
                CryptoWebhookReceipt::query()->forMerchant($merchantId)->where('crypto_payment_request_id', $request->id)
                    ->update(['status' => 'pending', 'processed_at' => null, 'next_attempt_at' => now(), 'last_error_code' => null]);
            }
        }, 3);
    }

    /** Reconcile a whole order against confirmed chain facts, including its older quote addresses. */
    public function reconcileOrder(
        int $merchantId, int $orderId, User $operator, string $requestKey,
        string $expectedHash, string $reason, ?string $mfaCode,
    ): CryptoPaymentResolution {
        $this->verifyOperator($operator, $mfaCode);

        return DB::transaction(function () use ($merchantId, $orderId, $operator, $requestKey, $expectedHash, $reason): CryptoPaymentResolution {
            $merchant = Merchant::query()->lockForUpdate()->findOrFail($merchantId);
            $actor = $this->authorizeOperator($operator, $merchant, Permissions::CRYPTO_PAYMENTS_RECONCILE, platformOnly: true);
            $order = Order::query()->withoutGlobalScope(MerchantScope::class)
                ->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId);
            $note = CryptoWithdrawalInput::text($reason, 500, required: true);
            if (! Str::isUuid($requestKey) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
                throw new BalanceOperationException(__('crypto.finance.errors.request'));
            }
            $existing = CryptoPaymentResolution::query()->forMerchant($merchantId)->where('request_key', strtolower($requestKey))->first();
            if ($existing) {
                if ((int) $existing->order_id !== $orderId || (int) $existing->operator_id !== (int) $actor->id
                    || $existing->evidence_hash !== $expectedHash || $existing->reason !== $note) {
                    throw new BalanceOperationException(__('crypto.finance.errors.key_conflict'));
                }

                return $existing;
            }
            $evidence = $this->evidence->capture($order, requireHealthy: true);
            if (! hash_equals($expectedHash, $evidence['hash'])) {
                throw new BalanceOperationException(__('crypto.review.errors.changed'));
            }
            $request = $evidence['request'];
            $income = CryptoLedgerEntry::query()->forMerchant($merchantId)->where('order_id', $orderId)->first();
            $previous = CryptoPaymentResolution::query()->forMerchant($merchantId)->where('order_id', $orderId)->whereIn('action', ['accept', 'adjust'])->latest('id')->first();
            if ($previous?->evidence_hash === $evidence['hash']
                && ! CryptoCheckoutGuard::requests($order)->contains(fn ($r) => $r->status === 'review' || $r->review_reason !== null)) {
                throw new BalanceOperationException(__('crypto.review.errors.unchanged'));
            }
            if (! $income && (! in_array($order->status, ['pending', 'expired'], true) || $order->paid_at !== null || $evidence['gross'] === '0' || ! $evidence['last_event'])) {
                throw new BalanceOperationException(__('crypto.review.errors.state'));
            }
            if ($income && ($order->paid_at === null || $income->network !== $request->network || $income->asset !== $request->asset)) {
                throw new BalanceOperationException(__('crypto.review.errors.state'));
            }
            $quote = $income?->fee_snapshot ?? $request->quote_snapshot;
            $calculated = $this->quotes->calculate($request->network.':'.$request->asset, $quote['fiat_amount'], $quote['currency'],
                $quote['fiat_usd_rate'], $quote['price_usd'], $quote['markup_percent'], $quote['fee_percent'], $quote['fee_fixed_usd']);
            foreach (['due_units', 'fee_units', 'net_units', 'network', 'asset', 'decimals'] as $field) {
                if ((string) $request->$field !== (string) $calculated[$field]) {
                    throw new BalanceOperationException(__('crypto.review.errors.evidence'));
                }
            }
            if (($quote['rounding'] ?? null) !== $calculated['rounding'] || ($quote['version'] ?? null) !== $calculated['version']) {
                throw new BalanceOperationException(__('crypto.review.errors.evidence'));
            }
            $gross = $evidence['gross'];
            $fee = $this->quotes->feeForGross($gross, $quote);
            $net = bcsub($gross, $fee, 0);
            $oldGross = $previous?->gross_after_units ?? $income?->gross_units ?? '0';
            $oldFee = $previous?->fee_after_units ?? $income?->fee_units ?? '0';
            $oldNet = $previous?->net_after_units ?? $income?->amount_units ?? '0';
            $balance = CryptoAssetBalance::query()->forMerchant($merchantId)->firstOrCreate([
                'merchant_id' => $merchantId, 'network' => $request->network, 'asset' => $request->asset,
            ], ['balance_units' => '0', 'reserved_units' => '0', 'debt_units' => '0']);
            $balance = CryptoAssetBalance::query()->forMerchant($merchantId)->lockForUpdate()->findOrFail($balance->id);
            $before = $balance->balance_units;
            $debtBefore = $balance->debt_units ?? '0';
            $this->applyDelta($balance, bcsub($net, $oldNet, 0));
            $resolution = CryptoPaymentResolution::query()->create([
                'merchant_id' => $merchantId, 'order_id' => $orderId, 'crypto_payment_request_id' => $request->id,
                'crypto_asset_balance_id' => $balance->id, 'operator_id' => $actor->id, 'request_key' => strtolower($requestKey),
                'network' => $request->network, 'asset' => $request->asset, 'action' => $income ? 'adjust' : 'accept',
                'evidence_hash' => $evidence['hash'], 'evidence_snapshot' => $evidence['snapshot'], 'fee_snapshot' => $quote,
                'gross_before_units' => $oldGross, 'gross_after_units' => $gross, 'fee_before_units' => $oldFee, 'fee_after_units' => $fee,
                'net_before_units' => $oldNet, 'net_after_units' => $net, 'balance_before_units' => $before, 'balance_after_units' => $balance->balance_units,
                'debt_before_units' => $debtBefore, 'debt_after_units' => $balance->debt_units, 'reserved_units' => $balance->reserved_units, 'reason' => $note,
            ]);
            if (! $income) {
                $income = CryptoLedgerEntry::query()->create([
                    'merchant_id' => $merchantId, 'order_id' => $orderId, 'crypto_payment_request_id' => $request->id,
                    'crypto_asset_balance_id' => $balance->id, 'idempotency_key' => 'crypto_order_paid:'.$orderId,
                    'type' => 'order_paid', 'network' => $request->network, 'asset' => $request->asset,
                    'gross_units' => $gross, 'fee_units' => $fee, 'amount_units' => $net,
                    'balance_before_units' => $before, 'balance_after_units' => $balance->balance_units,
                    'debt_before_units' => $debtBefore, 'debt_after_units' => $balance->debt_units, 'fee_snapshot' => $quote,
                ]);
                $request->update(['status' => 'credited', 'credited_at' => now(), 'review_reason' => null]);
                app(OrderPaymentStatusService::class)->confirmCryptoPayment($order, $request, $income, $evidence['last_event'], acceptExpired: true);
            }
            foreach (CryptoCheckoutGuard::requests($order) as $item) {
                $item->update(['status' => $item->id === $request->id ? 'credited' : 'resolved', 'review_reason' => null,
                    'settlement_next_attempt_at' => now()->addMinute()]);
            }

            return $resolution;
        }, 3);
    }

    /** Preserve active withdrawal holds; a reorg deficit is separate debt, offset by future credits/releases. */
    private function applyDelta(CryptoAssetBalance $balance, string $delta): void
    {
        $this->assertBalance($balance);
        $debt = CryptoAmount::units($balance->debt_units ?? '0');
        if (bccomp($delta, '0', 0) >= 0) {
            $balance->balance_units = bcadd($balance->balance_units, $delta, 0);
        } else {
            $debt = bcsub($debt, $delta, 0);
        }
        $free = bcsub($balance->balance_units, $balance->reserved_units, 0);
        $offset = bccomp($free, $debt, 0) > 0 ? $debt : $free;
        $balance->balance_units = CryptoAmount::units(bcsub($balance->balance_units, $offset, 0));
        $balance->debt_units = CryptoAmount::units(bcsub($debt, $offset, 0));
        $balance->save();
    }

    public function creditConfirmedRequest(int $merchantId, int $requestId): CryptoLedgerEntry
    {
        $request = CryptoPaymentRequest::query()->forMerchant($merchantId)->findOrFail($requestId);

        return DB::transaction(function () use ($merchantId, $request): CryptoLedgerEntry {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($request->order_id);
            $fresh = CryptoPaymentRequest::query()->forMerchant($merchantId)->where('order_id', $order->id)->lockForUpdate()->findOrFail($request->id);
            $key = 'crypto_order_paid:'.$order->id;
            $existing = CryptoLedgerEntry::query()->forMerchant($merchantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ((int) $existing->crypto_payment_request_id !== (int) $fresh->id) {
                    throw new DomainException('Another payment request has already credited this order.');
                }

                return $existing;
            }

            if ($order->payment_type !== 'crypto' || $order->status !== 'pending' || $order->paid_at !== null
                || ! in_array($fresh->status, ['confirming', 'confirmed'], true)) {
                throw new DomainException('Request is not eligible for automatic crypto credit.');
            }
            if (CryptoWebhookReceipt::query()->forMerchant($merchantId)->where('crypto_payment_request_id', $fresh->id)
                ->whereIn('status', ['pending', 'confirming', 'review'])->exists()
                || CryptoChainEvent::query()->forMerchant($merchantId)->where('crypto_payment_request_id', $fresh->id)
                    ->whereNull('orphaned_at')->where(fn ($query) => $query->whereNull('verified_at')->orWhereNull('confirmed_at'))->exists()) {
                throw new DomainException('Outstanding chain verification or review prevents automatic credit.');
            }

            $quote = $fresh->quote_snapshot;
            if (CryptoCheckoutGuard::requests($order)->contains(fn ($other) => $other->id !== $fresh->id
                && (CryptoCheckoutGuard::hasEvidence($other) || ! CryptoCheckoutGuard::covered($other)))) {
                throw new DomainException('Another quote has payment evidence or incomplete history.');
            }
            $calculated = $this->quotes->calculate(
                $fresh->network.':'.$fresh->asset, $quote['fiat_amount'], $quote['currency'],
                $quote['fiat_usd_rate'], $quote['price_usd'], $quote['markup_percent'], $quote['fee_percent'], $quote['fee_fixed_usd'],
            );
            foreach (['due_units', 'fee_units', 'net_units', 'network', 'asset', 'decimals'] as $field) {
                if ((string) $fresh->$field !== (string) $calculated[$field]) {
                    throw new DomainException('Crypto quote snapshot does not match the request.');
                }
            }
            if ($quote['rounding'] !== $calculated['rounding'] || $quote['version'] !== $calculated['version']) {
                throw new DomainException('Unsupported crypto quote version.');
            }

            $events = CryptoChainEvent::query()->forMerchant($merchantId)->where('crypto_payment_request_id', $fresh->id)
                ->whereNotNull('verified_at')->whereNotNull('confirmed_at')->whereNull('orphaned_at')
                ->orderBy('id')->lockForUpdate()->get();
            $address = CryptoAddress::query()->forMerchant($merchantId)->where('order_id', $order->id)
                ->where('network', $fresh->network)->findOrFail($fresh->crypto_address_id);
            $gross = '0';
            foreach ($events as $event) {
                if ($event->network !== $fresh->network || $event->asset !== $fresh->asset
                    || $event->block_time->lt($fresh->created_at) || $event->block_time->gt($fresh->expires_at)
                    || $event->block_height < $address->scan_from_block) {
                    throw new DomainException('Late or mismatched crypto transfer requires manual review.');
                }
                $gross = CryptoAmount::units(bcadd($gross, CryptoAmount::units($event->amount_units), 0));
            }
            if ($gross !== $fresh->due_units) {
                throw new DomainException('Underpayment or overpayment requires further processing.');
            }

            $balance = CryptoAssetBalance::query()->forMerchant($merchantId)->firstOrCreate([
                'merchant_id' => $merchantId, 'network' => $fresh->network, 'asset' => $fresh->asset,
            ], ['balance_units' => '0', 'reserved_units' => '0']);
            $balance = CryptoAssetBalance::query()->forMerchant($merchantId)->lockForUpdate()->findOrFail($balance->id);
            $before = CryptoAmount::units($balance->balance_units);
            $debtBefore = $balance->debt_units ?? '0';
            $this->applyDelta($balance, $fresh->net_units);
            $after = $balance->balance_units;
            $ledger = CryptoLedgerEntry::query()->create([
                'merchant_id' => $merchantId, 'order_id' => $order->id,
                'crypto_payment_request_id' => $fresh->id, 'crypto_asset_balance_id' => $balance->id,
                'idempotency_key' => $key, 'type' => 'order_paid', 'network' => $fresh->network, 'asset' => $fresh->asset,
                'gross_units' => $gross, 'fee_units' => $fresh->fee_units, 'amount_units' => $fresh->net_units,
                'balance_before_units' => $before, 'balance_after_units' => $after, 'fee_snapshot' => $quote,
                'debt_before_units' => $debtBefore, 'debt_after_units' => $balance->debt_units,
            ]);
            $fresh->update(['status' => 'credited', 'credited_at' => now()]);

            return $ledger;
        }, 3);
    }
}

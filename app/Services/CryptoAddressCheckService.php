<?php

namespace App\Services;

use App\Jobs\QueryCryptoAddressCheck;
use App\Models\CryptoAddressCheck;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Support\CryptoFinanceAccess;
use App\Support\CryptoRuntimeSettings;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CryptoAddressCheckService
{
    public const MAX_FAILURES = 3;

    public function __construct(
        private readonly CryptoHistoryScanService $history,
        private readonly CryptoReceiptVerificationService $verification,
        private readonly CryptoSettlementService $settlement,
    ) {}

    /** First cancellation fixes the two order-level slots, including every historical quote address. */
    public function schedule(int $merchantId, int $orderId, CarbonImmutable $cancelledAt): void
    {
        DB::transaction(function () use ($merchantId, $orderId, $cancelledAt): void {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId);
            if ($order->payment_type !== 'crypto') {
                throw new DomainException('Address checks require a crypto order.');
            }
            foreach ([3, 7] as $day) {
                CryptoAddressCheck::query()->forMerchant($merchantId)->firstOrCreate(['order_id' => $orderId, 'check_key' => 'day'.$day], [
                    'merchant_id' => $merchantId, 'kind' => 'day'.$day, 'cancelled_at' => $cancelledAt,
                    'due_at' => $cancelledAt->addHours($day * 24), 'status' => 'pending',
                ]);
            }
        }, 3);
    }

    /** Local, restartable deployment backfill; no external requests. */
    public function initialize(): int
    {
        $count = 0;
        DB::table('crypto_address_monitors as monitors')->join('crypto_payment_requests as requests', 'requests.id', '=', 'monitors.crypto_payment_request_id')
            ->whereColumn('monitors.merchant_id', 'requests.merchant_id')
            ->where(function ($missing): void {
                foreach (['day3', 'day7'] as $key) {
                    $missing->orWhereNotExists(fn ($plans) => $plans->selectRaw('1')->from('crypto_address_checks as plans')
                        ->whereColumn('plans.order_id', 'requests.order_id')->where('plans.check_key', $key));
                }
            })
            ->whereNotNull('monitors.cancelled_at')->select('requests.order_id', 'requests.merchant_id')
            ->selectRaw('MIN(monitors.cancelled_at) as cancelled_at')->groupBy('requests.order_id', 'requests.merchant_id')
            ->chunkById(200, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    $this->schedule((int) $row->merchant_id, (int) $row->order_id, CarbonImmutable::parse($row->cancelled_at));
                    $count++;
                }
            }, 'requests.order_id', 'order_id');

        return $count;
    }

    public function requestManual(User $user, Order $order): CryptoAddressCheck
    {
        return $this->enqueue($user, $order, 'manual');
    }

    public function requestReconciliation(User $user, Order $order): CryptoAddressCheck
    {
        return $this->enqueue($user, $order, 'recheck');
    }

    private function enqueue(User $user, Order $order, string $kind): CryptoAddressCheck
    {
        $this->authorize($user, $order, $kind === 'recheck');
        if (! CryptoRuntimeSettings::get('history_enabled')) {
            throw new DomainException(__('crypto.address_check.disabled'));
        }
        $check = DB::transaction(function () use ($user, $order, $kind): CryptoAddressCheck {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->merchant_id);
            $fresh = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $this->authorize($user, $fresh, $kind === 'recheck');
            $existing = CryptoAddressCheck::query()->forMerchant($fresh->merchant_id)->where('order_id', $fresh->id)->where('kind', $kind)
                ->where(fn ($q) => $q->whereIn('status', ['pending', 'running'])
                    ->when($kind === 'manual', fn ($recent) => $recent->orWhere('created_at', '>=', now()->subMinute())))
                ->latest('id')->first();
            if ($existing) {
                return $existing;
            }

            return CryptoAddressCheck::query()->create(['merchant_id' => $fresh->merchant_id, 'order_id' => $fresh->id,
                'check_key' => (string) Str::uuid(), 'kind' => $kind, 'requested_by' => $user->id,
                'due_at' => now(), 'status' => 'pending']);
        }, 3);
        $this->dispatch($check);

        return $check->refresh();
    }

    public function authorize(User $user, Order $order, bool $reconciliation = false): void
    {
        $fresh = User::query()->find($user->id);
        if ($reconciliation) {
            $merchant = Merchant::query()->withoutGlobalScopes()->findOrFail($order->merchant_id);
            if ($order->payment_type !== 'crypto' || $order->deleted_at !== null
                || ! CryptoFinanceAccess::allowed($fresh, Permissions::CRYPTO_PAYMENTS_RECONCILE, $merchant, platformOnly: true)) {
                throw new AuthorizationException;
            }

            return;
        }
        $ids = $fresh?->manageableMerchantIds();
        if (! $fresh?->status || ! $fresh->can(Permissions::ORDERS_VIEW) || $order->payment_type !== 'crypto'
            || $order->deleted_at !== null || ($ids !== null && ! in_array((int) $order->merchant_id, $ids, true))) {
            throw new AuthorizationException;
        }
    }

    public function dispatchDue(int $limit): int
    {
        if (! CryptoRuntimeSettings::get('history_enabled')) {
            return 0;
        }
        $checks = CryptoAddressCheck::query()->withoutGlobalScopes()->whereIn('status', ['pending', 'running', 'error'])
            ->where('failure_count', '<', self::MAX_FAILURES)
            ->where(fn ($query) => $query->where('kind', '!=', 'day3')->orWhereNotExists(fn ($later) => $later->selectRaw('1')
                ->from('crypto_address_checks as later')->whereColumn('later.order_id', 'crypto_address_checks.order_id')
                ->where('later.kind', 'day7')->where('later.due_at', '<=', now())))
            ->where(fn ($q) => $q->whereNull('last_error_code')->orWhere('last_error_code', '!=', 'access_revoked'))
            ->where('due_at', '<=', now())->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(15)))
            ->orderByDesc('due_at')->orderBy('id')->limit($limit)->get();
        $count = 0;
        foreach ($checks as $check) {
            $count += $this->dispatch($check) ? 1 : 0;
        }

        return $count;
    }

    private function dispatch(CryptoAddressCheck $check): bool
    {
        if ($check->status === 'completed' || $check->failure_count >= self::MAX_FAILURES || ! CryptoAddressCheck::query()->forMerchant($check->merchant_id)->whereKey($check->id)
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(15)))
            ->update(['dispatched_at' => now()])) {
            return false;
        }
        try {
            QueryCryptoAddressCheck::dispatch((int) $check->merchant_id, (int) $check->id)->afterCommit();
        } catch (\Throwable $exception) {
            $check->update(['dispatched_at' => null]);
            throw $exception;
        }

        return true;
    }

    /** Returns false when bounded work needs another queue turn; never waits for the three-hour scheduler. */
    public function process(int $merchantId, int $checkId): bool
    {
        $check = CryptoAddressCheck::query()->forMerchant($merchantId)->findOrFail($checkId);
        $lock = Cache::lock('crypto:address-check:'.$check->order_id, 120);
        if (! $lock->get()) {
            return false;
        }
        try {
            $check->refresh();
            if ($check->status === 'completed' || $check->due_at->isFuture() || $check->last_error_code === 'access_revoked' || $check->failure_count >= self::MAX_FAILURES) {
                return true;
            }
            if ($check->kind === 'day3' && CryptoAddressCheck::query()->forMerchant($merchantId)->where('order_id', $check->order_id)
                ->where('kind', 'day7')->where('due_at', '<=', now())->exists()) {
                return true;
            }
            if (! CryptoRuntimeSettings::get('history_enabled')) {
                $check->update(['status' => 'pending', 'dispatched_at' => null, 'next_attempt_at' => now()->addHours(3)]);

                return true;
            }
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->findOrFail($check->order_id);
            if ($order->payment_type !== 'crypto') {
                throw new DomainException('Address check binding changed.');
            }
            if (in_array($check->kind, ['manual', 'recheck'], true)) {
                $actor = User::query()->find($check->requested_by);
                if (! $actor) {
                    throw new AuthorizationException;
                }
                $this->authorize($actor, $order, $check->kind === 'recheck');
            }
            $check->update(['status' => 'running', 'started_at' => $check->started_at ?? now()->startOfSecond(),
                'attempts' => $check->attempts + 1, 'last_error_code' => null, 'next_attempt_at' => null]);
            $deadline = microtime(true) + 30;
            $requests = CryptoPaymentRequest::query()->forMerchant($merchantId)->where('order_id', $order->id)->orderBy('id')->get();
            if ($requests->isEmpty()) {
                throw new DomainException('Order has no receive address.');
            }
            $result = [];
            foreach ($requests as $request) {
                while ($request->history_error_code !== null || $request->history_query_state !== null || $request->history_checked_at === null
                    || $request->history_checked_at->lt($check->started_at)) {
                    if (microtime(true) >= $deadline) {
                        return false;
                    }
                    if (! $this->history->scan($request->network, $merchantId, $request->id)) {
                        return false;
                    }
                    $request->refresh();
                }
            }
            foreach ($requests as $request) {
                if (CryptoRuntimeSettings::get('verification_enabled')) {
                    $receipts = CryptoWebhookReceipt::query()->forMerchant($merchantId)->where('crypto_payment_request_id', $request->id)
                        ->where('status', 'pending')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->limit(25)->get();
                    foreach ($receipts as $receipt) {
                        if (microtime(true) >= $deadline) {
                            return false;
                        }
                        $receipt->refresh();
                        if ($receipt->status !== 'pending' || $receipt->next_attempt_at?->isFuture()) {
                            continue;
                        }
                        $this->verification->process($merchantId, $receipt->id);
                    }
                }
                $request->refresh();
                $result[] = ['request_id' => $request->id, 'address_id' => $request->crypto_address_id,
                    'checked_at' => $request->history_checked_at->toIso8601String(), 'status' => $request->status];
            }
            DB::transaction(function () use ($check, $requests, $result, $merchantId): void {
                $fresh = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($check->order_id);
                if (CryptoPaymentRequest::query()->forMerchant($merchantId)->where('order_id', $fresh->id)->count() !== $requests->count()) {
                    throw new DomainException('The order acquired another address during its query.');
                }
                $check->update(['status' => 'completed', 'completed_at' => now(), 'result' => $result,
                    'dispatched_at' => null, 'next_attempt_at' => null, 'last_error_code' => null]);
                if ($check->kind === 'day7') {
                    CryptoAddressCheck::query()->forMerchant($merchantId)->where('order_id', $check->order_id)->where('kind', 'day3')
                        ->where('status', '!=', 'completed')->update(['status' => 'completed', 'completed_at' => now(),
                            'dispatched_at' => null, 'next_attempt_at' => null, 'last_error_code' => null,
                            'result' => ['covered_by_check_id' => $check->id, 'addresses' => $result]]);
                }
            }, 3);
            if (CryptoRuntimeSettings::get('verification_enabled') && CryptoRuntimeSettings::get('settlement_enabled')) {
                foreach ($requests as $request) {
                    $this->settlement->settle($merchantId, $request->id);
                }
            }

            return true;
        } catch (\Throwable $exception) {
            if ($check->status !== 'completed') {
                $failures = $check->failure_count + 1;
                $check->update(['status' => 'error', 'dispatched_at' => null, 'failure_count' => $failures,
                    'next_attempt_at' => $failures < self::MAX_FAILURES ? now()->addHours(3) : null,
                    'last_error_code' => $exception instanceof AuthorizationException ? 'access_revoked' : 'address_query_failed']);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}

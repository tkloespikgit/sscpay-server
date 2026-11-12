<?php

namespace App\Services;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoCheckout;
use App\Models\CryptoPaymentRequest;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Support\CryptoCheckoutGuard;
use App\Support\CryptoVerificationPolicy;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Durable public checkout work. External reads run only in the bounded background consumer. */
final class CryptoCheckoutService
{
    public function __construct(
        private readonly CryptoPaymentRequestService $requests,
        private readonly CryptoPaymentConfigurationService $configuration,
        private readonly CryptoAddressMonitorService $monitors,
        private readonly CryptoBlockHistoryService $blocks,
        private readonly CryptoHistoryScanService $history,
        private readonly CryptoSettlementService $settlement,
    ) {}

    public function initialize(Order $order): void
    {
        if (! CryptoCheckoutGuard::available($order)) {
            throw new DomainException('Crypto checkout is unavailable.');
        }
        $method = PaymentMethod::query()->forMerchant($order->merchant_id)->findOrFail($order->payment_method_id);
        $this->configuration->validate($method->only(['payment_type', 'config_map_id', 'config']), $method);
        $fees = $method->configurationForMerchant($order->merchant);
        CryptoCheckout::query()->forMerchant($order->merchant_id)->firstOrCreate(['order_id' => $order->id], [
            'merchant_id' => $order->merchant_id, 'payment_method_id' => $method->id,
            'fee_snapshot' => ['fee_percent' => (string) $fees['fee_percent'], 'fee_fixed' => (string) $fees['fee_fixed']],
            'requested_at' => now(), 'next_attempt_at' => now(),
        ]);
        $order->update(['pay_url' => rtrim((string) config('app.url'), '/').route('payment.show', $order->payment_link_token, false)]);
    }

    /** A browser request records intent only; stale tabs cannot replace a newer quote. */
    public function open(int $merchantId, int $orderId, string $action, ?int $expectedRequestId): void
    {
        if (! in_array($action, ['open', 'requote'], true)) {
            throw new DomainException('Unknown checkout action.');
        }
        $lock = Cache::lock('crypto-checkout:'.$orderId, 600);
        if (! $lock->get()) {
            return;
        }
        try {
            DB::transaction(function () use ($merchantId, $orderId, $action, $expectedRequestId): void {
                [$order, $checkout] = $this->locked($merchantId, $orderId);
                if (! CryptoCheckoutGuard::available($order) || $checkout->current_request_id !== $expectedRequestId) {
                    throw new DomainException('Checkout is unavailable or the quote changed.');
                }
                if ($checkout->status === 'checking' || ($action === 'open' && $checkout->requested_at->gt(now()->subSeconds(30)))) {
                    return;
                }
                $current = $this->current($checkout);
                if ($action === 'requote' && (! $current || $current->expires_at->isFuture())) {
                    throw new DomainException('A valid quote cannot be replaced.');
                }
                $checkout->update(['action' => $action, 'phase' => 'backfill', 'status' => 'checking',
                    'requested_at' => now(), 'handoff_at' => null, 'ready_at' => null, 'next_attempt_at' => now(), 'error_code' => null]);
                $this->requireCoverage($order);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    public function process(int $merchantId, int $orderId): void
    {
        if (! CryptoCheckoutGuard::enabled()) {
            return;
        }
        $lock = Cache::lock('crypto-checkout:'.$orderId, 600);
        if (! $lock->get()) {
            return;
        }
        try {
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->findOrFail($orderId);
            $checkout = CryptoCheckout::query()->forMerchant($merchantId)->where('order_id', $orderId)->firstOrFail();
            if ($checkout->status !== 'checking' || $checkout->next_attempt_at?->isFuture()) {
                return;
            }
            $checkout->update(['next_attempt_at' => now()->addSeconds(30), 'error_code' => null]);
            if (! CryptoCheckoutGuard::available($order)) {
                $checkout->update(['status' => $order->paid_at ? 'paid' : 'blocked', 'next_attempt_at' => null]);

                return;
            }
            $current = $this->current($checkout);
            if (! $current) {
                $current = $this->prepareNext($order, $checkout);
            }
            if ($current->current_monitor_id === null) {
                $this->monitors->prepare($merchantId, $current->id, historyOnly: true);
            }
            $since = $checkout->phase === 'handoff' ? $checkout->handoff_at : $checkout->requested_at;
            $deadline = microtime(true) + 35;
            foreach (CryptoCheckoutGuard::requests($order) as $request) {
                for ($i = 0; $i < 5 && ! CryptoCheckoutGuard::covered($request, $since) && microtime(true) < $deadline; $i++) {
                    if (! $this->history->scan($request->network, $merchantId, $request->id)) {
                        break;
                    }
                    $request->refresh();
                }
                if (! CryptoCheckoutGuard::covered($request, $since)) {
                    return;
                }
                $this->settlement->settle($merchantId, $request->id);
            }
            $order->refresh();
            $current->refresh();
            if ($order->paid_at !== null) {
                $checkout->update(['status' => 'paid', 'next_attempt_at' => null]);

                return;
            }
            if (CryptoCheckoutGuard::requests($order)->contains(fn ($request) => CryptoCheckoutGuard::hasEvidence($request))) {
                return;
            }
            if (! $current->expires_at->isFuture()) {
                if ($checkout->action !== 'requote') {
                    $checkout->update(['status' => 'expired', 'next_attempt_at' => null]);

                    return;
                }
                CryptoCheckoutGuard::assertRequote($order, $current->id);
                $current = $this->prepareNext($order, $checkout, $current->id);
                $this->monitors->prepare($merchantId, $current->id);

                return;
            }
            if ($checkout->phase !== 'handoff') {
                $monitor = $this->monitors->prepare($merchantId, $current->id);
                if (! $this->monitors->synchronize($merchantId, $monitor->id)
                    || ! $this->monitors->checkActive($merchantId, $monitor->id)) {
                    $this->requireCoverage($order);

                    return;
                }
                DB::transaction(function () use ($merchantId, $orderId): void {
                    [$lockedOrder, $lockedCheckout] = $this->locked($merchantId, $orderId);
                    $lockedCheckout->update(['phase' => 'handoff', 'handoff_at' => now(), 'next_attempt_at' => now()]);
                    $this->requireCoverage($lockedOrder);
                }, 3);

                return;
            }
            if (! $this->monitors->checkActive($merchantId, $current->current_monitor_id)) {
                $checkout->update(['phase' => 'backfill', 'handoff_at' => null]);
                $this->requireCoverage($order);

                return;
            }
            DB::transaction(function () use ($merchantId, $orderId): void {
                [$order, $checkout] = $this->locked($merchantId, $orderId);
                $current = $this->current($checkout);
                $monitor = $current ? CryptoAddressMonitor::query()->forMerchant($merchantId)
                    ->where('crypto_payment_request_id', $current->id)->lockForUpdate()->find($current->current_monitor_id) : null;
                if (! CryptoCheckoutGuard::available($order) || ! $current?->expires_at->isFuture()
                    || $monitor?->status !== 'active' || ! $monitor->expires_at?->isFuture()
                    || CryptoCheckoutGuard::requests($order)->contains(fn ($request) => CryptoCheckoutGuard::hasEvidence($request)
                        || ! CryptoCheckoutGuard::covered($request, $checkout->handoff_at))) {
                    return;
                }
                $current->update(['status' => 'ready']);
                $checkout->update(['status' => 'ready', 'ready_at' => now(), 'next_attempt_at' => null, 'error_code' => null]);
            }, 3);
        } catch (\Throwable $error) {
            CryptoCheckout::query()->forMerchant($merchantId)->where('order_id', $orderId)->where('status', 'checking')
                ->update(['error_code' => 'preparation_retry', 'next_attempt_at' => now()->addMinute()]);
            throw new DomainException('Crypto checkout preparation will retry.', previous: $error);
        } finally {
            $lock->release();
        }
    }

    private function prepareNext(Order $order, CryptoCheckout $checkout, ?int $predecessorId = null): CryptoPaymentRequest
    {
        $request = CryptoPaymentRequest::query()->forMerchant($order->merchant_id)->where('order_id', $order->id)
            ->where('replaces_request_id', $predecessorId)->first();
        if (! $request) {
            $method = PaymentMethod::query()->forMerchant($order->merchant_id)->findOrFail($order->payment_method_id);
            $config = $this->configuration->validate($method->only(['payment_type', 'config_map_id', 'config']), $method)['config'];
            $policy = CryptoVerificationPolicy::snapshot($config['network']);
            $this->blocks->assertNetwork($config['network'], $policy['genesis_hash'] ?? null);
            $head = $this->blocks->head($config['network']);
            $request = $this->requests->prepareAllocated($order->merchant_id, $order->id, $method->id,
                hash('sha256', 'checkout:'.$order->id.':'.($predecessorId ?? 'initial')), $head['height'], $predecessorId);
        }
        DB::transaction(function () use ($order, $request): void {
            [, $checkout] = $this->locked($order->merchant_id, $order->id);
            $checkout->update(['current_request_id' => $request->id, 'action' => 'open', 'phase' => 'backfill',
                'handoff_at' => null, 'ready_at' => null, 'next_attempt_at' => now()]);
        }, 3);
        $checkout->refresh();

        return $request;
    }

    private function current(CryptoCheckout $checkout): ?CryptoPaymentRequest
    {
        return $checkout->current_request_id === null ? null : CryptoPaymentRequest::query()->forMerchant($checkout->merchant_id)
            ->where('order_id', $checkout->order_id)->where('payment_method_id', $checkout->payment_method_id)->findOrFail($checkout->current_request_id);
    }

    /** @return array{Order, CryptoCheckout} */
    private function locked(int $merchantId, int $orderId): array
    {
        Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);
        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId);
        $checkout = CryptoCheckout::query()->forMerchant($merchantId)->where('order_id', $orderId)->lockForUpdate()->firstOrFail();

        return [$order, $checkout];
    }

    private function requireCoverage(Order $order): void
    {
        CryptoPaymentRequest::query()->forMerchant($order->merchant_id)->where('order_id', $order->id)
            ->update(['history_checked_at' => null, 'history_next_attempt_at' => now()]);
    }
}

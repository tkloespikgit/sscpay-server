<?php

namespace App\Services;

use App\Models\CryptoAddress;
use App\Models\CryptoAddressMonitor;
use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\SystemConfig;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CryptoAddressMonitorService
{
    public function __construct(private readonly TatumSubscriptionClient $tatum) {}

    public function prepare(int $merchantId, int $requestId, bool $historyOnly = false): CryptoAddressMonitor
    {
        $this->assertEnabled();
        $request = CryptoPaymentRequest::query()->forMerchant($merchantId)->findOrFail($requestId);

        return DB::transaction(function () use ($merchantId, $request, $historyOnly): CryptoAddressMonitor {
            Merchant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($merchantId);
            $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($request->order_id);
            $fresh = CryptoPaymentRequest::query()->forMerchant($merchantId)->where('order_id', $order->id)->lockForUpdate()->findOrFail($request->id);
            $address = CryptoAddress::query()->forMerchant($merchantId)->where('order_id', $order->id)
                ->where('payment_method_id', $fresh->payment_method_id)->findOrFail($fresh->crypto_address_id);
            if ($address->network !== $fresh->network) {
                throw new DomainException('Payment request and permanent address binding do not match.');
            }
            $generation = 1;
            if ($fresh->current_monitor_id !== null) {
                $existing = CryptoAddressMonitor::query()->forMerchant($merchantId)->where('crypto_payment_request_id', $fresh->id)
                    ->lockForUpdate()->findOrFail($fresh->current_monitor_id);
                if ($existing->status !== 'cold') {
                    return $existing;
                }
                $maxRenewals = SystemConfig::get('crypto.subscription_reopen_max_renewals', '0');
                if (! is_string($maxRenewals) || ! preg_match('/^[0-9]{1,6}$/D', $maxRenewals)
                    || $existing->generation > (int) $maxRenewals || ! $existing->backfill_completed_at
                    || ! $existing->cancelled_at || $existing->backfill_completed_at->lt($existing->cancelled_at)) {
                    throw new DomainException('Restoring this subscription requires completed gap backfill and an allowed renewal.');
                }
                $generation = $existing->generation + 1;
            }
            if ($order->payment_type !== 'crypto' || $order->status !== 'pending' || $order->paid_at !== null
                || ! in_array($fresh->status, $historyOnly ? ['preparing', 'ready', 'expired'] : ['preparing', 'ready'], true)
                || (! $historyOnly && ! $fresh->expires_at->isFuture())) {
                throw new DomainException('Payment request is not eligible for subscription preparation.');
            }
            $environment = str_ends_with($fresh->network, '-mainnet') ? 'mainnet' : 'testnet';
            $ttl = $this->ttl($fresh);
            $uuid = (string) Str::uuid();
            $base = config('services.tatum.webhook_base_url');
            $parts = is_string($base) ? parse_url($base) : false;
            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ! in_array($parts['path'] ?? '', ['', '/'], true) || strlen($base) > 300) {
                throw new DomainException('A dedicated HTTPS webhook origin must be configured.');
            }
            $monitor = CryptoAddressMonitor::query()->create([
                'merchant_id' => $merchantId, 'crypto_payment_request_id' => $fresh->id, 'crypto_address_id' => $fresh->crypto_address_id,
                'generation' => $generation, 'callback_uuid' => $uuid,
                'callback_url' => rtrim($base, '/').route('webhooks.tatum', ['environment' => $environment, 'monitor' => $uuid], false),
                'network' => $fresh->network, 'environment' => $environment,
                'credential_fingerprint' => $this->tatum->credentialFingerprint($environment),
                'status' => 'preparing', 'ttl_seconds' => $ttl, 'next_attempt_at' => now(),
            ]);
            $fresh->update(['current_monitor_id' => $monitor->id]);

            return $monitor;
        }, 3);
    }

    public function synchronize(int $merchantId, int $monitorId): bool
    {
        $this->assertEnabled();

        return Cache::lock('crypto-subscription:'.$monitorId, 120)->block(5, function () use ($merchantId, $monitorId): bool {
            $monitor = CryptoAddressMonitor::query()->forMerchant($merchantId)->findOrFail($monitorId);
            if ($monitor->status === 'cold') {
                return true;
            }
            try {
                if ($monitor->credential_fingerprint !== $this->tatum->credentialFingerprint($monitor->environment)) {
                    $this->failure($monitor, 'credential_changed');

                    return false;
                }
                if ($monitor->status === 'cancelling' || ($monitor->status === 'active' && $monitor->expires_at?->isPast())) {
                    return $this->cancelExpired($monitor);
                }
                if ($monitor->status === 'active') {
                    return true;
                }
                $request = CryptoPaymentRequest::query()->forMerchant($merchantId)->findOrFail($monitor->crypto_payment_request_id);
                $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchantId)->findOrFail($request->order_id);
                $payable = ! $request->expires_at->isPast() && $request->credited_at === null
                    && $order->status === 'pending' && $order->paid_at === null;
                $monitor->update(['status' => 'creating']);
                $id = $monitor->subscription_id;
                $this->tatum->enableHmac($monitor->environment);
                if ($id === null) {
                    $matches = $this->tatum->matchingIds($monitor);
                    if (count($matches) > 1) {
                        $this->failure($monitor, 'subscription_conflict');

                        return false;
                    }
                    $id = $matches[0] ?? null;
                    if ($id === null) {
                        if (! $payable) {
                            $this->failure($monitor, 'quote_not_payable');

                            return false;
                        }
                        try {
                            $id = $this->tatum->create($monitor);
                        } catch (DomainException) {
                            $matches = $this->tatum->matchingIds($monitor);
                            if (count($matches) !== 1) {
                                $this->failure($monitor, 'create_result_uncertain');

                                return false;
                            }
                            $id = $matches[0];
                        }
                    }
                    $monitor->update(['subscription_id' => $id]);
                }
                $remote = $this->tatum->get($monitor->environment, $id);
                if ($remote === null || ! $this->tatum->matches($monitor, $remote) || ($remote['id'] ?? null) !== $id) {
                    $this->failure($monitor, 'remote_identity_mismatch');

                    return false;
                }
                $expires = $payable ? now()->addSeconds($monitor->ttl_seconds) : now();
                if ($expires->lt($request->expires_at)) {
                    $expires = $request->expires_at;
                }
                $monitor->update(['status' => 'active', 'activated_at' => now(), 'expires_at' => $expires,
                    'last_error_code' => null, 'next_attempt_at' => null]);

                return true;
            } catch (DomainException) {
                $this->failure($monitor, 'provider_failure');

                return false;
            }
        });
    }

    /** Reopening verifies remote existence without sliding the subscription lifetime. */
    public function checkActive(int $merchantId, int $monitorId): bool
    {
        $this->assertEnabled();

        return Cache::lock('crypto-subscription:'.$monitorId, 120)->block(5, function () use ($merchantId, $monitorId): bool {
            $monitor = CryptoAddressMonitor::query()->forMerchant($merchantId)->findOrFail($monitorId);
            if ($monitor->status !== 'active' || ! $monitor->expires_at?->isFuture() || $monitor->subscription_id === null
                || $monitor->credential_fingerprint !== $this->tatum->credentialFingerprint($monitor->environment)) {
                return false;
            }
            $remote = $this->tatum->get($monitor->environment, $monitor->subscription_id);
            if ($remote === null) {
                $monitor->update(['status' => 'cold', 'cancelled_at' => now(), 'backfill_completed_at' => null,
                    'last_error_code' => 'remote_subscription_missing']);

                return false;
            }

            return ($remote['id'] ?? null) === $monitor->subscription_id && $this->tatum->matches($monitor, $remote);
        });
    }

    private function cancelExpired(CryptoAddressMonitor $monitor): bool
    {
        if (! config('services.tatum.history_enabled')) {
            $this->failure($monitor, 'history_scanner_disabled');

            return false;
        }
        if ($monitor->subscription_id === null) {
            $this->failure($monitor, 'missing_subscription_id');

            return false;
        }
        try {
            $interval = $this->positiveSetting('crypto.history_scan_interval_seconds');
        } catch (DomainException) {
            $this->failure($monitor, 'history_configuration_missing');

            return false;
        }
        $address = CryptoAddress::query()->forMerchant($monitor->merchant_id)->findOrFail($monitor->crypto_address_id);
        $request = CryptoPaymentRequest::query()->forMerchant($monitor->merchant_id)->findOrFail($monitor->crypto_payment_request_id);
        $covered = $monitor->backfill_to_block !== null && $monitor->backfill_completed_at !== null && filled($monitor->backfill_to_hash)
            && $monitor->backfill_to_block >= $address->scan_from_block
            && $monitor->cold_scan_to_block !== null && $monitor->cold_scan_to_block >= $monitor->backfill_to_block
            && filled($monitor->cold_scan_to_hash) && $monitor->cold_scan_checked_at !== null
            && ! $monitor->cold_scan_checked_at->isFuture() && $monitor->cold_scan_checked_at->gte(now()->subSeconds($interval));
        $pendingReceipts = CryptoWebhookReceipt::query()->forMerchant($monitor->merchant_id)
            ->where('crypto_payment_request_id', $monitor->crypto_payment_request_id)->whereIn('status', ['pending', 'confirming', 'review'])->exists();
        $pendingEvents = CryptoChainEvent::query()->forMerchant($monitor->merchant_id)->where('crypto_payment_request_id', $monitor->crypto_payment_request_id)
            ->whereNull('orphaned_at')->where(fn ($query) => $query->whereNull('confirmed_at')->orWhereNull('verified_at'))->exists();
        if (! $covered || $pendingReceipts || $pendingEvents || $request->status === 'confirming') {
            $this->failure($monitor, ! $covered ? 'history_coverage_required' : 'verification_pending');

            return false;
        }
        $monitor->update(['status' => 'cancelling']);
        $remote = $this->tatum->get($monitor->environment, $monitor->subscription_id);
        if ($remote !== null) {
            if (! $this->tatum->identifies($monitor, $remote) || ($remote['id'] ?? null) !== $monitor->subscription_id) {
                $this->failure($monitor, 'remote_identity_mismatch');

                return false;
            }
            $this->tatum->cancel($monitor->environment, $monitor->subscription_id);
        }
        $monitor->update(['status' => 'cold', 'cancelled_at' => now(), 'last_error_code' => null, 'next_attempt_at' => null]);

        return true;
    }

    private function failure(CryptoAddressMonitor $monitor, string $code): void
    {
        $monitor->refresh();
        $attempts = $monitor->attempts + 1;
        $monitor->update([
            'status' => in_array($monitor->status, ['active', 'cancelling'], true) ? $monitor->status : 'error',
            'attempts' => $attempts, 'last_error_code' => $code,
            'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min(7, $attempts - 1)))),
        ]);
    }

    private function ttl(CryptoPaymentRequest $request): int
    {
        $overrides = SystemConfig::getArray('crypto.subscription_ttl_overrides');
        $value = $overrides[$request->network.':'.$request->asset] ?? SystemConfig::get('crypto.subscription_ttl_seconds');
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{0,6}$/D', (string) $value)) {
            throw new DomainException('Subscription duration must be explicitly configured.');
        }

        return (int) $value;
    }

    private function positiveSetting(string $key): int
    {
        $value = SystemConfig::get($key);
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,6}$/D', $value)) {
            throw new DomainException('Subscription safety settings must be explicitly configured.');
        }

        return (int) $value;
    }

    private function assertEnabled(): void
    {
        if (! config('services.tatum.subscriptions_enabled')) {
            throw new DomainException('Tatum subscriptions are disabled.');
        }
    }
}

<?php

namespace App\Support;

use App\Models\CryptoChainEvent;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoWebhookReceipt;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\SystemConfig;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class CryptoCheckoutGuard
{
    public static function enabled(): bool
    {
        return (bool) config('services.tatum.checkout_enabled') && (bool) config('services.tatum.subscriptions_enabled')
            && (bool) config('services.tatum.history_enabled') && (bool) config('services.tatum.verification_enabled')
            && (bool) config('services.tatum.settlement_enabled');
    }

    public static function available(Order $order): bool
    {
        return self::enabled() && $order->payment_type === 'crypto' && $order->status === 'pending' && $order->paid_at === null
            && $order->created_at->gt(now()->subDays((int) SystemConfig::get('payment_link.expire_days', 7)))
            && (bool) $order->merchant?->status && (bool) $order->application?->status
            && PaymentMethod::query()->forMerchant($order->merchant_id)->whereKey($order->payment_method_id)
                ->where('payment_type', 'crypto')->where('is_active', true)
                ->whereHas('configMap', fn ($query) => $query->where('payment_type', 'crypto')->where('is_active', true))->exists();
    }

    /** @return Collection<int, CryptoPaymentRequest> */
    public static function requests(Order $order): Collection
    {
        return CryptoPaymentRequest::query()->forMerchant($order->merchant_id)->where('order_id', $order->id)->orderBy('id')->get();
    }

    public static function hasEvidence(CryptoPaymentRequest $request): bool
    {
        return $request->credited_at !== null || $request->review_reason !== null || $request->status === 'review'
            || CryptoChainEvent::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)->whereNull('orphaned_at')->exists()
            || CryptoWebhookReceipt::query()->forMerchant($request->merchant_id)->where('crypto_payment_request_id', $request->id)
                ->whereIn('status', ['pending', 'confirming', 'review'])->exists();
    }

    public static function covered(CryptoPaymentRequest $request, ?CarbonInterface $since = null): bool
    {
        $interval = SystemConfig::get('crypto.history_scan_interval_seconds');
        $maxAge = config('services.tatum.history_max_head_age_seconds');
        if (! is_string($interval) || ! preg_match('/^[1-9][0-9]{0,6}$/D', $interval)
            || ! preg_match('/^[1-9][0-9]{0,4}$/D', (string) $maxAge) || (int) $maxAge > 86400) {
            return false;
        }

        return $request->history_error_code === null && $request->history_to_block !== null && filled($request->history_to_hash)
            && $request->history_checked_at !== null && ! $request->history_checked_at->isFuture()
            && $request->history_checked_at->gte(now()->subSeconds((int) $interval))
            && ($since === null || $request->history_checked_at->gte($since))
            && $request->history_to_time !== null && $request->history_to_time->gte(now()->subSeconds((int) $maxAge));
    }

    /** Called while holding the merchant and order locks; no remote I/O. */
    public static function assertRequote(Order $order, int $predecessorId): void
    {
        $requests = self::requests($order);
        $last = $requests->last();
        if (! self::available($order) || ! $last || $last->id !== $predecessorId || $last->expires_at->isFuture()
            || $requests->count() >= 50 || $requests->contains(fn ($request) => self::hasEvidence($request) || ! self::covered($request))
            || ! $last->history_to_time->gt($last->expires_at)) {
            throw new DomainException('Requote requires complete history and no payment evidence.');
        }
    }
}

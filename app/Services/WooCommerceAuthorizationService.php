<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type AuthorizationState array{user_id: int, merchant_id: ?int, payment_method_id: ?int, domain: string, reference: string, expires_at: int, credentials?: array{consumer_key: string, consumer_secret: string}}
 */
class WooCommerceAuthorizationService
{
    /** @return array{token: string, url: string} */
    public function begin(User $actor, string $domain, ?PaymentMethod $paymentMethod = null): array
    {
        $actor = $this->authorizedUser($actor->id, $paymentMethod?->id);
        $domain = $this->normalizeDomain($domain);
        $appUrl = rtrim((string) config('app.url'), '/');

        if (strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) !== 'https') {
            throw ValidationException::withMessages(['data.domain' => __('admin.payment_method.woocommerce.https_required')]);
        }

        $rateLimitKey = 'woocommerce-authorization:'.$actor->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            throw ValidationException::withMessages(['data.domain' => __('admin.payment_method.woocommerce.too_many_attempts')]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $token = bin2hex(random_bytes(32));
        $reference = bin2hex(random_bytes(16));
        $state = [
            'user_id' => $actor->id,
            'merchant_id' => $actor->merchant_id,
            'payment_method_id' => $paymentMethod?->id,
            'domain' => $domain,
            'reference' => $reference,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ];
        $this->storeState($token, $state);

        return [
            'token' => $token,
            'url' => $domain.'/wc-auth/v1/authorize?'.http_build_query([
                'app_name' => config('app.name'),
                'scope' => 'read_write',
                'user_id' => $reference,
                'return_url' => $appUrl.route('woocommerce.authorization.return', ['locale' => app()->getLocale()], absolute: false),
                'callback_url' => $appUrl.route('woocommerce.authorization.callback', ['token' => $token], absolute: false),
            ], '', '&', PHP_QUERY_RFC3986),
        ];
    }

    /** @param array{user_id: string, consumer_key: string, consumer_secret: string} $credentials */
    public function receive(string $token, array $credentials): void
    {
        Cache::lock($this->cacheKey($token).':lock', 10)->block(3, function () use ($token, $credentials): void {
            $state = $this->state($token);
            abort_if($state === null, 404);
            abort_unless(hash_equals($state['reference'], $credentials['user_id']), 403);
            $actor = $this->authorizedUser($state['user_id'], $state['payment_method_id']);
            abort_unless($actor->merchant_id === $state['merchant_id'], 403);

            $keys = [
                'consumer_key' => $credentials['consumer_key'],
                'consumer_secret' => $credentials['consumer_secret'],
            ];
            abort_if(isset($state['credentials']) && $state['credentials'] !== $keys, 409);

            $state['credentials'] = $keys;
            $this->storeState($token, $state);
        });
    }

    /** @return array{status: string, credentials?: array{consumer_key: string, consumer_secret: string}} */
    public function consume(User $actor, string $token, string $domain, ?PaymentMethod $paymentMethod = null): array
    {
        $actor = $this->authorizedUser($actor->id, $paymentMethod?->id);

        return Cache::lock($this->cacheKey($token).':lock', 10)->block(3, function () use ($actor, $token, $domain, $paymentMethod): array {
            $state = $this->state($token);
            if ($state === null) {
                return ['status' => 'expired'];
            }

            if ($state['user_id'] !== $actor->id || $state['merchant_id'] !== $actor->merchant_id
                || $state['payment_method_id'] !== $paymentMethod?->id) {
                throw new AuthorizationException;
            }

            if ($state['domain'] !== $this->normalizeDomain($domain)) {
                Cache::forget($this->cacheKey($token));

                return ['status' => 'changed'];
            }

            if (! isset($state['credentials'])) {
                return ['status' => 'pending'];
            }

            Cache::forget($this->cacheKey($token));

            return ['status' => 'ready', 'credentials' => $state['credentials']];
        });
    }

    public function cancel(User $actor, string $token): void
    {
        Cache::lock($this->cacheKey($token).':lock', 10)->block(3, function () use ($actor, $token): void {
            $state = $this->state($token);
            if ($state !== null && $state['user_id'] === $actor->id) {
                Cache::forget($this->cacheKey($token));
            }
        });
    }

    public function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $parts = parse_url($domain);

        if (strlen($domain) > 255 || ! filter_var($domain, FILTER_VALIDATE_URL)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['data.domain' => __('admin.payment_method.validation.domain_format')]);
        }

        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';

        return 'https://'.strtolower($parts['host']).$port.rtrim($parts['path'] ?? '', '/');
    }

    private function authorizedUser(int $userId, ?int $paymentMethodId): User
    {
        $actor = User::query()->find($userId);
        if (! $actor?->status || ! $actor->can(Permissions::PAYMENT_METHODS_MANAGE)
            || ($actor->merchant_id !== null && ! $actor->merchant()->where('status', true)->exists())) {
            throw new AuthorizationException;
        }

        if ($paymentMethodId !== null) {
            $paymentMethod = PaymentMethod::query()->withoutGlobalScope(MerchantScope::class)->find($paymentMethodId);
            if ($paymentMethod === null || $paymentMethod->payment_type !== 'fiat' || ! $paymentMethod->canBeManagedBy($actor)) {
                throw new AuthorizationException;
            }
        }

        return $actor;
    }

    private function cacheKey(string $token): string
    {
        return 'woocommerce-authorization:'.hash('sha256', $token);
    }

    /** @return AuthorizationState|null */
    private function state(string $token): ?array
    {
        $encrypted = Cache::get($this->cacheKey($token));
        if ($encrypted === null) {
            return null;
        }

        $state = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);

        return $state['expires_at'] > now()->timestamp ? $state : null;
    }

    /** @param AuthorizationState $state */
    private function storeState(string $token, array $state): void
    {
        Cache::put($this->cacheKey($token), Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)),
            now()->setTimestamp($state['expires_at']));
    }
}

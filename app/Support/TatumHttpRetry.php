<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/** Bounded retries for safe reads and idempotent HMAC configuration, never subscription creation. */
final class TatumHttpRetry
{
    private const MAX_RATE_LIMIT_DELAY_SECONDS = 5;

    public static function shouldRetry(?Throwable $error): bool
    {
        return $error instanceof ConnectionException || ($error instanceof RequestException
            && ($error->response->serverError() || ($error->response->status() === 429
                && self::rateLimitDelay($error) <= self::MAX_RATE_LIMIT_DELAY_SECONDS * 1000)));
    }

    public static function delay(int $attempt, Throwable $error): int
    {
        return $error instanceof RequestException && $error->response->status() === 429
            ? self::rateLimitDelay($error) : 100;
    }

    private static function rateLimitDelay(RequestException $error): int
    {
        $retryAfter = trim((string) $error->response->header('Retry-After'));
        if (ctype_digit($retryAfter)) {
            if (strlen($retryAfter) > 9 || (int) $retryAfter > self::MAX_RATE_LIMIT_DELAY_SECONDS) {
                return (self::MAX_RATE_LIMIT_DELAY_SECONDS + 1) * 1000;
            }

            return max(1000, (int) $retryAfter * 1000);
        }
        $date = strlen($retryAfter) <= 128 && ! str_contains($retryAfter, "\0")
            ? DateTimeImmutable::createFromFormat(DATE_RFC7231, $retryAfter, new DateTimeZone('UTC')) : false;
        if ($date === false) {
            return 1000;
        }
        $seconds = $date->getTimestamp() - now()->getTimestamp();

        return $seconds > self::MAX_RATE_LIMIT_DELAY_SECONDS
            ? (self::MAX_RATE_LIMIT_DELAY_SECONDS + 1) * 1000 : max(1000, $seconds * 1000);
    }
}

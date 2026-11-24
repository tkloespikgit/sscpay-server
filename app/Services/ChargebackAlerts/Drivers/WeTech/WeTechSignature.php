<?php

namespace App\Services\ChargebackAlerts\Drivers\WeTech;

use App\Services\ChargebackAlerts\WebhookRejected;
use JsonException;
use stdClass;

final class WeTechSignature
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @return array<string, mixed> */
    public function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, false, 24, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WebhookRejected;
        }

        if (! $decoded instanceof stdClass) {
            throw new WebhookRejected;
        }

        return get_object_vars($decoded);
    }

    /** @param array<string, mixed> $parameters */
    public function sign(array $parameters, string $secret): string
    {
        unset($parameters['sign']);
        ksort($parameters, SORT_STRING);
        $pairs = [];
        foreach ($parameters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/', $key)) {
                throw new WebhookRejected;
            }
            if (is_string($value) && preg_match('/&[A-Za-z][A-Za-z0-9_]*=/', $value)) {
                throw new WebhookRejected;
            }

            $text = is_string($value) ? $value : json_encode(
                $value,
                self::JSON_FLAGS | (is_array($value) || $value instanceof stdClass ? JSON_PRESERVE_ZERO_FRACTION : 0),
            );
            $pairs[] = $key.'='.$text;
        }

        return base64_encode(hash_hmac('sha1', implode('&', $pairs), $secret, true));
    }
}

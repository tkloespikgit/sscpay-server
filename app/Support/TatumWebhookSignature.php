<?php

namespace App\Support;

final class TatumWebhookSignature
{
    /** Returns the verified byte policy. Never re-encodes numbers, strings, or object key order. */
    public function verify(string $raw, ?string $header, string $environment): ?string
    {
        if (! in_array($environment, ['mainnet', 'testnet'], true) || ! is_string($header) || strlen($header) !== 88) {
            return null;
        }
        $signature = base64_decode($header, true);
        if ($signature === false || strlen($signature) !== 64 || base64_encode($signature) !== $header) {
            return null;
        }
        $secret = config('services.tatum.'.$environment.'_hmac_secret');
        if (! is_string($secret) || strlen($secret) < 16 || strlen($secret) > 100) {
            return null;
        }
        if (hash_equals(hash_hmac('sha512', $raw, $secret, true), $signature)) {
            return 'raw';
        }
        $compact = $this->compactWhitespace($raw);

        return hash_equals(hash_hmac('sha512', $compact, $secret, true), $signature) ? 'json_whitespace_v1' : null;
    }

    private function compactWhitespace(string $raw): string
    {
        $result = '';
        $inString = false;
        $escaped = false;
        for ($index = 0, $length = strlen($raw); $index < $length; $index++) {
            $character = $raw[$index];
            if ($inString) {
                $result .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
            } elseif ($character === '"') {
                $inString = true;
                $result .= $character;
            } elseif (! in_array($character, [' ', "\n", "\r", "\t"], true)) {
                $result .= $character;
            }
        }

        return $result;
    }
}

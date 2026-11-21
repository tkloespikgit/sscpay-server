<?php
namespace PaymentGatewayAggregator\Core\Exceptions;

if (!defined('ABSPATH')) { exit; }

/** Preserve PayPal's business error so callers can distinguish funding failure from an outage. */
class PayPalCaptureException extends \RuntimeException
{
    private $body;

    public function __construct(array $body)
    {
        $this->body = $body;
        parent::__construct('PayPal捕获支付失败：' . wp_json_encode($body));
    }

    public function isInstrumentDeclined(): bool
    {
        foreach ($this->body['details'] ?? [] as $detail) {
            if (is_array($detail) && ($detail['issue'] ?? '') === 'INSTRUMENT_DECLINED') {
                return true;
            }
        }
        return false;
    }

    public function debugId(): ?string
    {
        return is_string($this->body['debug_id'] ?? null) ? $this->body['debug_id'] : null;
    }

    public function recoveryUrl(): ?string
    {
        foreach ($this->body['links'] ?? [] as $link) {
            if (is_array($link) && in_array($link['rel'] ?? '', ['redirect', 'payer-action', 'approve'], true)) {
                $url = self::validatedRedirectUrl($link['href'] ?? null);
                if ($url !== null) { return $url; }
            }
        }
        return null;
    }

    /** Only PayPal's checkout hosts may become a browser redirect, including cached recovery links. */
    public static function validatedRedirectUrl($url): ?string
    {
        if (!is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return null; }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), ['www.paypal.com', 'www.sandbox.paypal.com'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }
        return $url;
    }
}

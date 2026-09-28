<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

final class CheckoutCountryRecommendation
{
    public static function forRequest(Request $request, array $availableCountries): ?string
    {
        // The country header is trustworthy only when the connection came from a
        // Cloudflare edge. Direct requests may supply an arbitrary CF-IPCountry.
        $remoteIp = $request->server('REMOTE_ADDR');
        $country = strtoupper((string) $request->header('CF-IPCountry'));

        if ($remoteIp && IpUtils::checkIp($remoteIp, CloudflareIpRanges::all())
            && array_key_exists($country, $availableCountries)) {
            return $country;
        }

        return null;
    }
}

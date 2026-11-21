<?php

namespace PaymentGatewayAggregator\Core;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (!defined('ABSPATH')) {
    exit;
}

class UpdateChecker
{
    private const SLUG = 'sscpay-gateway-aggregator';
    private const UPDATE_PATH = '/plugins/gateway/updates/v1';
    private const DEFAULT_BASE_URL = 'https://www.ssecurepay.com';
    public const SOURCE_OPTION = 'pga_plugin_update_source';
    public const BASE_OPTION = 'pga_update_base_url';

    private static ?object $instance = null;

    public static function sourceUrl(): ?string
    {
        if (self::usesLegacyRepository()) {
            $repository = is_string(PGA_UPDATE_REPO_URL) ? rtrim(trim(PGA_UPDATE_REPO_URL), '/') : '';

            return preg_match('~\Ahttps://github\.com(/[a-z0-9-]+/[a-z0-9_.-]+)\z~i', $repository, $matches) === 1
                ? 'https://github.com' . $matches[1] : null;
        }

        $baseUrl = defined('PGA_UPDATE_BASE_URL') ? PGA_UPDATE_BASE_URL : (get_site_option(self::BASE_OPTION, '') ?: self::DEFAULT_BASE_URL);
        $baseUrl = is_string($baseUrl) ? self::normalizeBaseUrl($baseUrl) : null;

        return $baseUrl !== null ? $baseUrl . self::UPDATE_PATH : null;
    }

    public static function normalizeBaseUrl(string $baseUrl): ?string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || strpos($baseUrl, '\\') !== false || ($parts['scheme'] ?? '') !== 'https'
            || array_diff_key($parts, array_flip(['scheme', 'host', 'port'])) !== []) {
            return null;
        }

        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '';

        return 'https://' . strtolower($parts['host']) . $port;
    }

    public static function init(string $pluginFile): void
    {
        if (!class_exists(PucFactory::class) || self::$instance !== null) {
            return;
        }

        $source = self::sourceUrl();
        $legacy = self::usesLegacyRepository();
        self::refreshCachedSource($source ?? '');
        if ($source === null) {
            return;
        }

        $checker = PucFactory::buildUpdateChecker($source, $pluginFile, self::SLUG, 12);
        if ($legacy) {
            $checker->getVcsApi()->enableReleaseAssets();
        } else {
            self::configurePublicSource($checker, $source);
        }

        self::$instance = $checker;
    }

    public static function instance(): ?object
    {
        return self::$instance;
    }

    private static function usesLegacyRepository(): bool
    {
        return !defined('PGA_UPDATE_BASE_URL') && defined('PGA_UPDATE_REPO_URL') && !get_site_option(self::BASE_OPTION, '');
    }

    private static function refreshCachedSource(string $source): void
    {
        if (get_site_option(self::SOURCE_OPTION) === $source) {
            return;
        }

        delete_site_option('external_updates-' . self::SLUG);
        delete_site_transient('update_plugins');
        update_site_option(self::SOURCE_OPTION, $source);
    }

    private static function configurePublicSource(object $checker, string $source): void
    {
        $downloadPattern = '~\A' . preg_quote($source, '~') . '/[0-9]+\.[0-9]+\.[0-9]+/download\z~';
        remove_filter('http_request_host_is_external', [$checker, 'allowMetadataHost'], 10);

        add_filter('http_request_args', static function (array $args, string $url) use ($source, $downloadPattern): array {
            $requestUrl = explode('?', $url, 2)[0];
            if (($args['method'] ?? 'GET') !== 'GET' || ($requestUrl !== $source && preg_match($downloadPattern, $url) !== 1)) {
                return $args;
            }

            $args['reject_unsafe_urls'] = true;
            $args['sslverify'] = true;
            $args['redirection'] = 0;

            return $args;
        }, 10, 2);

        $checker->addResultFilter(static function (?object $release) use ($source): ?object {
            $version = $release->version ?? '';
            $downloadUrl = $release->download_url ?? '';

            return is_string($version) && preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version) === 1
                && $downloadUrl === $source . '/' . $version . '/download' ? $release : null;
        });
    }
}

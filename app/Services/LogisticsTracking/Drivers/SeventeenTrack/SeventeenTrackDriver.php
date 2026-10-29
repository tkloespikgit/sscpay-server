<?php

namespace App\Services\LogisticsTracking\Drivers\SeventeenTrack;

use App\Services\LogisticsTracking\Contracts\LogisticsTrackingDriver;
use App\Services\LogisticsTracking\DTOs\DriverCredentials;
use App\Services\LogisticsTracking\DTOs\RegisterResult;
use App\Services\LogisticsTracking\DTOs\TrackingNumber;
use App\Services\LogisticsTracking\DTOs\TrackSnapshot;
use App\Services\LogisticsTracking\LogisticsTrackingManager;
use Carbon\Carbon;
use Throwable;

/**
 * 17TRACK 物流轨迹驱动：封装 register / gettrackinfo / Webhook 验签与解析。
 * Base URL 优先用商户配置，留空则回落 DEFAULT_BASE_URL；Token 与 Secret 均来自商户设置。
 */
class SeventeenTrackDriver implements LogisticsTrackingDriver
{
    public const NAME = 'seventeen_track';

    public const DEFAULT_BASE_URL = 'https://api.17track.net/track/v2.4';

    /** 物流单号已经注册过，不需重复注册（当作成功，避免 Job 空转与重复扣量误判）。 */
    public const ERROR_ALREADY_REGISTERED = -18019901;

    public function __construct(
        private readonly SeventeenTrackWebhookVerifier $verifier,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isPlatformSelected(): bool
    {
        return app(LogisticsTrackingManager::class)->isDriverAvailable(self::NAME);
    }

    public function register(TrackingNumber $number, DriverCredentials $credentials): RegisterResult
    {
        $client = $this->client($credentials);
        $payload = ['number' => $number->number, 'auto_detection' => true];

        if (filled($number->carrierCode) && ctype_digit($number->carrierCode)) {
            $payload['carrier'] = (int) $number->carrierCode;
            unset($payload['auto_detection']);
        }

        $result = $client->register([$payload]);
        $accepted = $result['accepted'][0] ?? null;
        $rejected = $result['rejected'][0] ?? null;

        if (is_array($rejected)) {
            $errorCode = (int) data_get($rejected, 'error.code', data_get($rejected, 'code', 0));

            if ($errorCode === self::ERROR_ALREADY_REGISTERED) {
                $carrier = isset($rejected['carrier'])
                    ? (string) (int) $rejected['carrier']
                    : $number->carrierCode;

                return new RegisterResult(accepted: true, carrierCode: $carrier ?: null);
            }

            return new RegisterResult(
                accepted: false,
                error: mb_substr(json_encode($rejected, JSON_UNESCAPED_UNICODE) ?: 'register rejected', 0, 1000),
            );
        }

        $carrier = isset($accepted['carrier']) ? (string) (int) $accepted['carrier'] : $number->carrierCode;

        return new RegisterResult(accepted: true, carrierCode: $carrier ?: null);
    }

    public function getTrackInfo(TrackingNumber $number, DriverCredentials $credentials): ?TrackSnapshot
    {
        $client = $this->client($credentials);
        $payload = ['number' => $number->number];

        if (filled($number->carrierCode) && ctype_digit($number->carrierCode)) {
            $payload['carrier'] = (int) $number->carrierCode;
        }

        $result = $client->getTrackInfo([$payload]);
        $accepted = $result['accepted'][0] ?? null;

        if (! is_array($accepted)) {
            $rejected = $result['rejected'][0] ?? null;

            if ($rejected) {
                throw new \RuntimeException(
                    mb_substr(json_encode($rejected, JSON_UNESCAPED_UNICODE) ?: 'gettrackinfo rejected', 0, 1000)
                );
            }

            return null;
        }

        return $this->snapshotFromTrackItem($accepted);
    }

    public function verifyWebhook(string $rawBody, ?string $signatureHeader, string $webhookSecret): bool
    {
        return $this->verifier->verify($rawBody, $signatureHeader, $webhookSecret);
    }

    public function parseWebhook(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return [];
        }

        $event = (string) ($payload['event'] ?? '');

        if ($event !== '' && $event !== 'TRACKING_UPDATED') {
            return [];
        }

        $snapshots = [];

        foreach ($this->extractTrackItems($payload) as $item) {
            $snapshot = $this->snapshotFromTrackItem($item);

            if ($snapshot !== null) {
                $snapshots[] = $snapshot;
            }
        }

        return $snapshots;
    }

    /** 商户覆盖优先，否则使用 17TRACK 默认 Tracking API 根地址。 */
    public function resolveBaseUrl(?string $override = null): string
    {
        $url = filled($override) ? (string) $override : self::DEFAULT_BASE_URL;

        return rtrim($url, '/');
    }

    /** 按商户凭证构造出站 HTTP 客户端。 */
    private function client(DriverCredentials $credentials): SeventeenTrackClient
    {
        return new SeventeenTrackClient(
            $credentials->apiToken,
            $this->resolveBaseUrl($credentials->baseUrl),
        );
    }

    /**
     * 将 17TRACK track item（accepted 行或 Webhook data）映射为 TrackSnapshot。
     *
     * @param  array<string, mixed>  $item
     */
    private function snapshotFromTrackItem(array $item): ?TrackSnapshot
    {
        $number = trim((string) ($item['number'] ?? ''));

        if ($number === '') {
            return null;
        }

        $carrier = isset($item['carrier']) ? (string) (int) $item['carrier'] : null;
        $trackInfo = is_array($item['track_info'] ?? null) ? $item['track_info'] : [];
        $latestStatus = is_array($trackInfo['latest_status'] ?? null) ? $trackInfo['latest_status'] : [];
        $latestEvent = is_array($trackInfo['latest_event'] ?? null) ? $trackInfo['latest_event'] : [];

        $status = filled($latestStatus['status'] ?? null) ? (string) $latestStatus['status'] : null;
        $subStatus = filled($latestStatus['sub_status'] ?? null) ? (string) $latestStatus['sub_status'] : null;
        $eventAt = $this->parseEventTime($latestEvent);
        $description = $this->eventDescription($latestEvent);
        $deliveredAt = $status === 'Delivered' ? ($eventAt ?? now()) : null;

        return new TrackSnapshot(
            number: $number,
            carrierCode: $carrier,
            status: $status,
            subStatus: $subStatus,
            lastEventAt: $eventAt,
            lastEventDesc: $description,
            deliveredAt: $deliveredAt,
        );
    }

    /**
     * 从 Webhook JSON 抽出可解析的 track item 列表（兼容单对象 / accepted 数组）。
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractTrackItems(array $payload): array
    {
        $data = $payload['data'] ?? $payload;

        if (! is_array($data)) {
            return [];
        }

        if (isset($data['number'])) {
            return [$data];
        }

        $accepted = $data['accepted'] ?? null;

        if (! is_array($accepted)) {
            return [];
        }

        $items = [];

        foreach ($accepted as $row) {
            if (is_array($row) && isset($row['number'])) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $latestEvent
     */
    private function parseEventTime(array $latestEvent): ?Carbon
    {
        foreach (['time_utc', 'time_iso'] as $key) {
            if (filled($latestEvent[$key] ?? null)) {
                try {
                    return Carbon::parse((string) $latestEvent[$key]);
                } catch (Throwable) {
                    // try next
                }
            }
        }

        $raw = $latestEvent['time_raw'] ?? null;

        if (is_array($raw) && filled($raw['date'] ?? null)) {
            try {
                $time = (string) ($raw['time'] ?? '00:00:00');

                return Carbon::parse(trim($raw['date'].' '.$time));
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $latestEvent
     */
    private function eventDescription(array $latestEvent): ?string
    {
        if (filled($latestEvent['description'] ?? null)) {
            return (string) $latestEvent['description'];
        }

        $translated = $latestEvent['description_translation'] ?? null;

        if (is_array($translated) && filled($translated['description'] ?? null)) {
            return (string) $translated['description'];
        }

        return null;
    }
}

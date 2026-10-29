<?php

namespace App\Services\LogisticsTracking\Drivers\SeventeenTrack;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 17TRACK Tracking API v2.4 出站客户端（register / gettrackinfo）。
 * Token / Base URL 由构造注入（商户 api_token + 商户 base_url 或驱动默认）。
 */
class SeventeenTrackClient
{
    /** 官方单次批量上限。 */
    public const BATCH_LIMIT = 40;

    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
    ) {}

    /**
     * 注册运单到 17TRACK。
     *
     * @param  list<array{number: string, carrier?: int, auto_detection?: bool}>  $items
     * @return array{accepted: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    public function register(array $items): array
    {
        return $this->post('register', $items);
    }

    /**
     * 主动查询运单轨迹。
     *
     * @param  list<array{number: string, carrier?: int}>  $items
     * @return array{accepted: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    public function getTrackInfo(array $items): array
    {
        return $this->post('gettrackinfo', $items);
    }

    /**
     * POST /{path}，要求业务 code=0；头部使用 17token。
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{accepted: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    private function post(string $path, array $items): array
    {
        if ($items === []) {
            return ['accepted' => [], 'rejected' => []];
        }

        if (count($items) > self::BATCH_LIMIT) {
            throw new RuntimeException('17TRACK 单次最多 '.self::BATCH_LIMIT.' 条运单');
        }

        $token = $this->token;
        $base = rtrim($this->baseUrl, '/');

        if ($token === '' || $base === '') {
            throw new RuntimeException('17TRACK 缺少商户 Token 或 Base URL');
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    '17token' => $token,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$base}/{$path}", $items)
                ->throw();
        } catch (RequestException $exception) {
            Log::warning('17TRACK API 请求失败', [
                'path' => $path,
                'status' => $exception->response?->status(),
                'body' => $exception->response?->json(),
            ]);

            throw $exception;
        }

        $body = $response->json();
        $code = (int) ($body['code'] ?? -1);

        if ($code !== 0) {
            Log::warning('17TRACK API 业务错误', ['path' => $path, 'body' => $body]);

            throw new RuntimeException('17TRACK API 返回错误码 '.$code);
        }

        $data = $body['data'] ?? [];

        return [
            'accepted' => is_array($data['accepted'] ?? null) ? array_values($data['accepted']) : [],
            'rejected' => is_array($data['rejected'] ?? null) ? array_values($data['rejected']) : [],
        ];
    }
}

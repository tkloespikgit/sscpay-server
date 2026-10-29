<?php

namespace App\Services\LogisticsTracking\Contracts;

use App\Services\LogisticsTracking\DTOs\DriverCredentials;
use App\Services\LogisticsTracking\DTOs\RegisterResult;
use App\Services\LogisticsTracking\DTOs\TrackingNumber;
use App\Services\LogisticsTracking\DTOs\TrackSnapshot;

/**
 * 物流轨迹 Provider 契约：注册、主动查、Webhook 验签与解析。
 *
 * 出站凭证由调用方按商户注入（DriverCredentials），实现类不读系统级密钥。
 * available_drivers 可预填未实现名供商户选用；真正出站调用前须能经 Manager::driver() 解析。
 */
interface LogisticsTrackingDriver
{
    /** 稳定驱动标识（如 seventeen_track），写入 tracking_provider / Webhook 路径。 */
    public function name(): string;

    /** 平台是否将本驱动列入可选枚举（不含商户选用/凭证）。 */
    public function isPlatformSelected(): bool;

    /** 向 Provider 注册运单，供后续推送轨迹。 */
    public function register(TrackingNumber $number, DriverCredentials $credentials): RegisterResult;

    /** 主动拉取最新轨迹；无数据时返回 null。 */
    public function getTrackInfo(TrackingNumber $number, DriverCredentials $credentials): ?TrackSnapshot;

    /** 校验 Webhook 原始 body 与签名头；secret 为商户配置的 webhook_secret。 */
    public function verifyWebhook(string $rawBody, ?string $signatureHeader, string $webhookSecret): bool;

    /**
     * 解析 Webhook body 为标准化快照列表；无关事件可返回空数组。
     *
     * @return list<TrackSnapshot>
     */
    public function parseWebhook(string $rawBody): array;
}

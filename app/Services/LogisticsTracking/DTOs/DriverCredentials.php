<?php

namespace App\Services\LogisticsTracking\DTOs;

/**
 * 出站调用物流 Provider 所需的商户侧凭证（由 MerchantLogisticsTrackingSetting 组装）。
 * 不读系统级密钥；baseUrl 可空，由具体 Driver 使用自身默认地址。
 */
readonly class DriverCredentials
{
    public function __construct(
        public string $apiToken,
        public string $webhookSecret,
        public ?string $baseUrl = null,
    ) {}
}

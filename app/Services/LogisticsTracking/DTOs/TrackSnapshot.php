<?php

namespace App\Services\LogisticsTracking\DTOs;

use Carbon\CarbonInterface;

/**
 * 物流轨迹标准化快照（Webhook 解析或主动查询后的统一形态）。
 * SyncService 据此回写 OrderShipping 的 tracking_* / 异常相关字段。
 */
readonly class TrackSnapshot
{
    public function __construct(
        public string $number,
        public ?string $carrierCode = null,
        public ?string $status = null,
        public ?string $subStatus = null,
        public ?CarbonInterface $lastEventAt = null,
        public ?string $lastEventDesc = null,
        public ?CarbonInterface $deliveredAt = null,
    ) {}
}

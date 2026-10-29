<?php

namespace App\Services\LogisticsTracking\DTOs;

/**
 * 注册 / 查询时传给 Driver 的运单标识；carrierCode 可空（由 Provider 自动识别）。
 */
readonly class TrackingNumber
{
    public function __construct(
        public string $number,
        public ?string $carrierCode = null,
    ) {}
}

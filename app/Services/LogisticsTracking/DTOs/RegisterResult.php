<?php

namespace App\Services\LogisticsTracking\DTOs;

/**
 * Provider 注册运单结果：accepted 表示对方已受理；carrierCode 为识别出的承运商编码（可空）。
 */
readonly class RegisterResult
{
    public function __construct(
        public bool $accepted,
        public ?string $carrierCode = null,
        public ?string $error = null,
    ) {}
}

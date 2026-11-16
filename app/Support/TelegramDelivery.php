<?php

namespace App\Support;

final readonly class TelegramDelivery
{
    public function __construct(
        public bool $suppressed,
        public bool $anyUsable,
        public bool $sent,
    ) {}

    public static function suppressed(): self
    {
        return new self(true, false, false);
    }
}

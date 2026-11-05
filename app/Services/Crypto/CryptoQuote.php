<?php

namespace App\Services\Crypto;

use Carbon\CarbonInterface;

/**
 * USD→代币报价快照：rate 表示 1 代币值多少 USD（与 Tatum /v4/data/rate 语义一致）。
 */
final readonly class CryptoQuote
{
    public const SOURCE_PEG = 'peg';

    public const SOURCE_TATUM = 'tatum';

    public const SOURCE_CACHE = 'cache';

    public function __construct(
        public string $rate,
        public string $source,
        public CarbonInterface $quotedAt,
    ) {}
}

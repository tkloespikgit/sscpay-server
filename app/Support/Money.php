<?php

namespace App\Support;

/** 金额按十进制截断，避免浮点和四舍五入。 */
final class Money
{
    /**
     * 保留指定小数位，其后数字直接舍去。
     */
    public static function truncate(string $amount, int $scale = 2): string
    {
        return bcadd($amount, '0', $scale);
    }
}

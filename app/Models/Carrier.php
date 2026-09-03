<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 系统支持的物流承运商清单（由 CarrierSeeder 落库）。order/ship API、
 * 手工录入物流、CSV 批量导入物流，三个写入 logistics_company 的入口
 * 都要求传入值必须能在这张表里按 carrier_code 匹配到，否则拒绝并提示
 * 联系管理员在 CarrierResource 里添加，见 isValidCode()。
 */
class Carrier extends Model
{
    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'carrier_name',
        'carrier_code',
        'country_code',
        'country_name',
        'status',
        'pp_supported',
    ];

    protected function casts(): array
    {
        return [
            'pp_supported' => 'boolean',
        ];
    }

    /**
     * logistics_company 传入值的合法性校验：忽略大小写匹配 carrier_code，
     * 且只认已启用的承运商。
     */
    public static function isValidCode(string $code): bool
    {
        return static::query()
            ->where('status', self::STATUS_ENABLED)
            ->whereRaw('UPPER(carrier_code) = ?', [mb_strtoupper($code)])
            ->exists();
    }
}

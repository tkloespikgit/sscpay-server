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

    /**
     * 把本地存的 logistics_company（即 carrier_code）换算成商城系统插件
     * /sync-tracking 接口要的 carrier_code + is_other_carrier（见
     * doc/s-system-sync-tracking.md 第三节"承运商字段说明"）：
     * pp_supported=true 时是渠道方（目前仅 PayPal）认识的标准代码，原样传并
     * 标 N；pp_supported=false（渠道枚举里没有对应项）则改传承运商名称自由
     * 文本，标 Y。找不到承运商记录属异常情况（入库前已校验过），兜底按
     * 标准代码处理。
     *
     * @return array{0: string, 1: string} [carrier_code, is_other_carrier]
     */
    public static function resolveTrackingCode(string $logisticsCompany): array
    {
        $carrier = static::query()
            ->whereRaw('UPPER(carrier_code) = ?', [mb_strtoupper($logisticsCompany)])
            ->first();

        if ($carrier && ! $carrier->pp_supported) {
            return [$carrier->carrier_name, 'Y'];
        }

        return [$logisticsCompany, 'N'];
    }
}

<?php

namespace App\Support;

use App\Models\User;
use App\Services\MerchantRoleProvisioningService;

/**
 * 静态配置演示可见屏。
 *
 * 平台管理员（超管）和商户级管理员可看入驻向导、商户配置、支付方式配置。
 * 商户管理员和支付通道管理员只看支付方式配置，且账号与所属商户须启用。
 * 只认所属商户名下的这两个默认角色，不因单独授予支付方式权限而放行。
 */
final class StaticConfigShowcase
{
    public const ONBOARD = 'onboard';

    public const MERCHANT = 'merchant';

    public const PAYMENT = 'payment';

    /**
     * @return list<string>
     */
    public static function screensFor(?User $user): array
    {
        if (! $user instanceof User || ! $user->status) {
            return [];
        }

        if ($user->isPlatformStaff()) {
            return [self::ONBOARD, self::MERCHANT, self::PAYMENT];
        }

        if (self::canSeePaymentDemo($user)) {
            return [self::PAYMENT];
        }

        return [];
    }

    public static function allows(?User $user, string $screen): bool
    {
        return in_array($screen, self::screensFor($user), true);
    }

    private static function canSeePaymentDemo(User $user): bool
    {
        if (! $user->merchant_id || ! $user->merchant?->status) {
            return false;
        }

        return $user->roles()
            ->where('roles.merchant_id', $user->merchant_id)
            ->whereIn('roles.name', [
                MerchantRoleProvisioningService::MERCHANT_ADMIN_LABEL,
                MerchantRoleProvisioningService::PAYMENT_CHANNEL_ADMIN_LABEL,
            ])
            ->exists();
    }
}

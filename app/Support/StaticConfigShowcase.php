<?php

namespace App\Support;

use App\Models\User;

/**
 * 静态配置演示可见屏。
 *
 * 平台账号（超管、商户级管理员）可看入驻向导、商户配置、支付方式配置。
 * 普通商户只看支付方式配置，且须账号启用、所属商户启用，并具备支付方式管理权限。
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

        if ($user->can(Permissions::PAYMENT_METHODS_MANAGE) && (bool) $user->merchant?->status) {
            return [self::PAYMENT];
        }

        return [];
    }

    public static function allows(?User $user, string $screen): bool
    {
        return in_array($screen, self::screensFor($user), true);
    }
}

<?php

namespace App\Filament\Support;

use App\Exceptions\BalanceOperationException;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Forms\Components\OneTimeCodeInput;

/**
 * 资金操作的强制身份验证（step-up 2FA）。所有会改动余额的写操作都必须：
 *   1. 操作者已绑定身份验证器（app authentication secret 非空）；
 *   2. 本次操作现场输入正确的 TOTP 验证码，且该码不可重复使用（防重放）。
 *
 * 系统复用 Filament 已接入的 AppAuthentication（TOTP），不改动登录策略，
 * 仅在资金动作上做操作级强制。校验失败抛 BalanceOperationException，
 * 由调用的 Filament 动作捕获并转成 danger 通知。
 */
class FinanceSecurity
{
    public static function isAuthenticatorBound(User $user): bool
    {
        return filled($user->getAppAuthenticationSecret());
    }

    /**
     * 资金操作确认弹窗里统一使用的验证码输入组件。
     */
    public static function codeField(): OneTimeCodeInput
    {
        return OneTimeCodeInput::make('mfa_code')
            ->label(__('admin.finance.security.code_label'))
            ->helperText(__('admin.finance.security.code_help'))
            ->required();
    }

    /**
     * 校验操作者是否可执行资金操作：必须已绑定验证器 + 验证码正确（防重放）。
     *
     * @throws BalanceOperationException
     */
    public static function assertVerified(User $user, ?string $code): void
    {
        if (! self::isAuthenticatorBound($user)) {
            throw new BalanceOperationException(__('admin.finance.security.not_bound'));
        }

        $verified = app(AppAuthentication::class)->verifyCode(
            (string) $code,
            $user->getAppAuthenticationSecret(),
            shouldPreventCodeReuse: true,
        );

        if (! $verified) {
            throw new BalanceOperationException(__('admin.finance.security.invalid_code'));
        }
    }
}

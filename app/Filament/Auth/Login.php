<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

/**
 * 登录页只填"账号"，不要求邮箱格式：真正拿去 Auth::attempt() 比对的还是
 * users.email 列，转换逻辑见 User::emailForAccount()（账号里带 @ 就原样当邮箱用，
 * 兼容历史上的真实邮箱账号，不会把老账号锁死）。
 *
 * 只覆写这三个方法，不碰 authenticate() 本身——MFA 二次验证流程完全复用父类实现。
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('account')
            ->label(__('admin.user.fields.account'))
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => User::emailForAccount($data['account']),
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.account' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}

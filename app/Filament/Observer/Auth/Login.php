<?php

namespace App\Filament\Observer\Auth;

use App\Models\Observer;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

/**
 * 登录页只填"账号"，不要求邮箱格式，写法对齐 App\Filament\Auth\Login（管理员/
 * 商户登录页），换成 Observer::emailForAccount() 做转换。
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('account')
            ->label(__('observer.auth.account'))
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => Observer::emailForAccount($data['account']),
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

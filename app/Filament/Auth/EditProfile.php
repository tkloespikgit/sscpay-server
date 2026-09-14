<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * 后台已经改成"账号登录"（见 App\Filament\Auth\Login / User::emailForAccount()），
 * 个人资料页不应该再让用户自己改登录用的邮箱：把邮箱输入框换成只读展示的账号，
 * disabled() + dehydrated(false) 双重保证——即使前端被绕过，save() 时这个字段
 * 也不会出现在提交的 $data 里，不可能被改掉。
 */
class EditProfile extends BaseEditProfile
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['account'] = User::accountFromEmail($data['email'] ?? null);

        return $data;
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('account')
            ->label(__('admin.user.fields.account'))
            ->disabled()
            ->dehydrated(false);
    }

    /**
     * 父类原本的 visible() 还判断"邮箱有没有改过"（$get('email') !== 当前邮箱）
     * 来决定要不要露出这个字段——但邮箱字段已经被上面换成叫 'account' 且不参与
     * dehydrate，$get('email') 在这里永远拿不到值，那个判断会失真，所以只保留
     * "改了密码才需要验证当前密码"这一条。
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('currentPassword')
            ->label(__('filament-panels::auth/pages/edit-profile.form.current_password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.current_password.validation_attribute'))
            ->belowContent(__('filament-panels::auth/pages/edit-profile.form.current_password.below_content'))
            ->password()
            ->autocomplete('current-password')
            ->currentPassword(guard: Filament::getAuthGuard())
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->visible(fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false);
    }
}

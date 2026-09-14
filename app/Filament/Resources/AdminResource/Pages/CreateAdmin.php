<?php

namespace App\Filament\Resources\AdminResource\Pages;

use App\Filament\Resources\AdminResource;
use App\Models\User;
use App\Services\PlatformRoleProvisioningService;
use Filament\Resources\Pages\CreateRecord;

class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    /**
     * 这份名单只建平台侧账号，merchant_id 强制落 NULL，不管表单是否传了别的值。
     *
     * 'account' 转成落库用的 email（见 User::emailForAccount()）并直接标记
     * email_verified_at——这个"邮箱"是内部合成出来的，不需要真的走一遍邮件验证。
     *
     * 不是超级管理员就是商户级管理员（两者是这份名单里唯二的账号类型），
     * 统一赋 PlatformRoleProvisioningService 里那个全平台共享的"商户级管理员"角色；
     * 超级管理员本身靠 Gate::before 短路所有权限判断，不需要额外挂角色。
     */
    protected function handleRecordCreation(array $data): User
    {
        $data['merchant_id'] = null;
        $data['email'] = User::emailForAccount($data['account']);
        $data['email_verified_at'] = now();
        unset($data['account']);

        /** @var User $user */
        $user = static::getModel()::create($data);

        if (! $user->is_super_admin) {
            $user->assignRole(app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole());
        }

        return $user;
    }
}

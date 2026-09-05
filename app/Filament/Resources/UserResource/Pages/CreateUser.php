<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\PlatformRoleProvisioningService;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * 'roles' 和 'is_merchant_manager' 都不是 users 表的真实字段，是表单里的
     * 虚拟字段，创建用户前必须先摘掉，否则 User::create() 会因为多出不存在的
     * 字段报错（即使 $fillable 里没写它，Eloquent 在某些配置下仍可能尝试写入
     * 未知属性）。
     *
     * 商户级管理员（is_merchant_manager 勾选）没有走"选商户下的角色"这条线——
     * 它们是平台侧账号，统一赋 PlatformRoleProvisioningService 里那个全平台共享的
     * "商户级管理员"角色，merchant_id 强制落 NULL。
     *
     * 用 provisionMerchantManagerRole() 拿具体的 Role 实例去 assignRole()，
     * 而不是拿角色名字符串——roles 表的唯一约束是 (merchant_id, name, guard_name)，
     * 如果某个商户碰巧也建了一个同名自定义角色，按名字 assignRole() 有可能
     * 解析到错的那一条（同 UserResource 类注释里提到的角色名歧义问题）。
     *
     * 密码不需要在这里手动 Hash::make()——User 模型的 casts 里
     * 'password' => 'hashed'，Eloquent 保存时会自动处理。
     */
    protected function handleRecordCreation(array $data): User
    {
        $roleIds = $data['roles'] ?? [];
        unset($data['roles']);

        $isMerchantManager = (bool) ($data['is_merchant_manager'] ?? false);
        unset($data['is_merchant_manager']);

        if ($isMerchantManager) {
            $data['merchant_id'] = null;
        }

        /** @var User $user */
        $user = static::getModel()::create($data);

        if ($isMerchantManager) {
            $user->assignRole(app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole());
        } elseif (! empty($roleIds)) {
            $roles = Role::query()->whereIn('id', $roleIds)->get();
            $user->syncRoles($roles);
        }

        return $user;
    }
}

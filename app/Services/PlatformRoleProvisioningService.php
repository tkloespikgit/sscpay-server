<?php

namespace App\Services;

use App\Support\Permissions;
use Spatie\Permission\Models\Role;

/**
 * 平台级角色（roles.merchant_id = NULL）的配置。目前只有"商户级管理员"一个角色，
 * 全平台共享同一条 Role 记录（不像商户角色那样每个商户各建一份）——因为商户级管理员
 * 靠 users.merchant_id 为 NULL + merchants.owner_id 做范围限定，权限集本身对所有
 * 商户级管理员都是一样的，不需要按商户拆分。
 *
 * 触发时机见 PermissionSeeder::run()（部署时幂等跑一遍），以及 CreateUser/EditUser
 * 把某个账号勾选为"商户级管理员"时调 provisionMerchantManagerRole() 拿到具体的
 * Role 实例去 assignRole()/syncRoles()（不用角色名字符串，避免和商户自建的
 * 同名角色混淆——roles 表的唯一约束是 (merchant_id, name, guard_name)）。
 */
class PlatformRoleProvisioningService
{
    public const ROLE_MERCHANT_MANAGER = '商户级管理员';

    public function provisionMerchantManagerRole(): Role
    {
        $role = Role::query()->firstOrCreate([
            'merchant_id' => null,
            'name' => self::ROLE_MERCHANT_MANAGER,
            'guard_name' => 'web',
        ]);

        $role->syncPermissions(Permissions::platformMerchantManager());

        return $role;
    }
}

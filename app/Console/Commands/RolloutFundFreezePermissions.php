<?php

namespace App\Console\Commands;

use App\Services\MerchantRoleProvisioningService;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 一次性命令：给资金冻结功能上线前已存在的商户，把新增的 3 个权限补发到
 * 其"商户管理员"默认角色上（按产品要求，这个功能只下发给商户管理员和
 * 超级管理员，不下发给财务管理员等其它默认子角色）。
 *
 * MerchantRoleProvisioningService::provisionDefaultRoles() 只在新商户创建时
 * 跑一次并用 syncPermissions() 整体覆盖角色权限，存量商户已经建好的角色
 * 不会自动感知 Permissions.php 里新增的常量。本命令用 givePermissionTo()
 * （增量授予，不是覆盖）避免冲掉商户自己在这个默认角色上加过的自定义权限。
 *
 * 只精确匹配角色名等于"商户管理员"的角色；商户改过默认角色名字或纯自建角色的，
 * 不在本命令的覆盖范围内——命令会输出实际改动了多少条角色，方便运维人工核对遗漏。
 *
 * 不注册进 routes/console.php 的调度，部署后手动执行一次：
 *   php artisan permissions:rollout-fund-freezes
 */
class RolloutFundFreezePermissions extends Command
{
    protected $signature = 'permissions:rollout-fund-freezes';

    protected $description = '给存量商户的"商户管理员"角色补发资金冻结相关权限';

    private const PERMISSIONS = [
        Permissions::FUND_FREEZES_VIEW,
        Permissions::FUND_FREEZES_CREATE,
        Permissions::FUND_FREEZES_RELEASE,
    ];

    public function handle(): int
    {
        foreach (self::PERMISSIONS as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        $roles = Role::query()->where('name', MerchantRoleProvisioningService::MERCHANT_ADMIN_LABEL)->get();

        foreach ($roles as $role) {
            $role->givePermissionTo(self::PERMISSIONS);
        }

        $this->info("角色「".MerchantRoleProvisioningService::MERCHANT_ADMIN_LABEL."」：更新了 {$roles->count()} 条角色记录。");

        return self::SUCCESS;
    }
}

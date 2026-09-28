<?php

namespace App\Console\Commands;

use App\Support\Permissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 一次性命令：给收款链接功能上线前已存在的商户，把新增的两个权限补发到
 * 「商户管理员」角色上。
 *
 * 背景与 RolloutOrderDisputePermissions 完全一致：
 * MerchantRoleProvisioningService::provisionDefaultRoles() 只在新商户创建时
 * 跑一次（商户管理员拿 Permissions::merchantScoped() 的全集），存量商户已经
 * 建好的角色不会自动感知 Permissions.php 里新增的常量。
 *
 * 用 givePermissionTo()（增量授予）而不是 syncPermissions()（整体覆盖），
 * 避免冲掉商户自己在默认角色上加过的自定义权限。
 *
 * 只补发给「商户管理员」：收款链接直接关系到收款金额与品牌域名，
 * 默认不下放给订单/物流/财务管理员，需要的话由商户自己在角色里勾选。
 *
 * 不进调度，部署后手动执行一次：
 *   php artisan permissions:rollout-checkout-links
 */
class RolloutCheckoutLinkPermissions extends Command
{
    protected $signature = 'permissions:rollout-checkout-links';

    protected $description = '给存量商户的「商户管理员」角色补发收款链接与自有域名权限';

    private const PERMISSIONS = [
        Permissions::CHECKOUT_LINKS_MANAGE,
        Permissions::MERCHANT_DOMAINS_MANAGE,
    ];

    public function handle(): int
    {
        foreach (self::PERMISSIONS as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        // 生产用 Redis 缓存权限列表：不清缓存的话，下面 givePermissionTo()
        // 仍会读到不含新权限的旧缓存并抛 PermissionDoesNotExist。
        // 必须在创建权限记录之后、授予角色之前清掉（同 PermissionSeeder 的注释）。
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = Role::query()->where('name', '商户管理员')->get();

        foreach ($roles as $role) {
            $role->givePermissionTo(self::PERMISSIONS);
        }

        $this->info("角色「商户管理员」：更新了 {$roles->count()} 条角色记录。");

        // 平台侧的「商户级管理员」角色走 PlatformRoleProvisioningService，
        // 它用的是 Permissions::platformMerchantManager()（同样包含 merchantScoped
        // 全集），重新跑一次 PermissionSeeder 即可同步，这里提示一下避免遗漏。
        $this->line('平台侧「商户级管理员」角色请另外执行：php artisan db:seed --class=PermissionSeeder');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Support\Permissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 一次性命令：给争议审核事件功能上线前已存在的商户，把新增的 4 个权限
 * 补发到其默认角色（商户管理员/财务管理员/订单管理员）上。
 *
 * MerchantRoleProvisioningService::provisionDefaultRoles() 只在新商户创建时
 * 跑一次并用 syncPermissions() 整体覆盖角色权限，存量商户已经建好的角色
 * 不会自动感知 Permissions.php 里新增的常量。本命令用 givePermissionTo()
 * （增量授予，不是覆盖）避免冲掉商户自己在这些默认角色上加过的自定义权限。
 *
 * 只精确匹配角色名等于默认标签的角色；商户改过默认角色名字或纯自建角色的，
 * 不在本命令的覆盖范围内，这是已知的局限——命令会输出实际改动了多少条角色，
 * 方便运维人工核对遗漏。
 *
 * 不注册进 routes/console.php 的调度，部署后手动执行一次：
 *   php artisan permissions:rollout-order-disputes
 */
class RolloutOrderDisputePermissions extends Command
{
    protected $signature = 'permissions:rollout-order-disputes';

    protected $description = '给存量商户的默认角色补发争议审核事件相关权限';

    /** @var array<string, list<string>> 角色名 => 需要补发的权限列表 */
    private const ROLE_PERMISSIONS = [
        '商户管理员' => [
            Permissions::ORDER_DISPUTES_VIEW,
            Permissions::ORDER_DISPUTES_OPEN,
            Permissions::ORDER_DISPUTES_REPLY,
            Permissions::ORDER_DISPUTES_CLOSE,
        ],
        '财务管理员' => [
            Permissions::ORDER_DISPUTES_VIEW,
            Permissions::ORDER_DISPUTES_OPEN,
            Permissions::ORDER_DISPUTES_CLOSE,
        ],
        '订单管理员' => [
            Permissions::ORDER_DISPUTES_VIEW,
            Permissions::ORDER_DISPUTES_REPLY,
        ],
    ];

    public function handle(): int
    {
        foreach ([
            Permissions::ORDER_DISPUTES_VIEW,
            Permissions::ORDER_DISPUTES_OPEN,
            Permissions::ORDER_DISPUTES_REPLY,
            Permissions::ORDER_DISPUTES_CLOSE,
        ] as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $roles = Role::query()->where('name', $roleName)->get();

            foreach ($roles as $role) {
                $role->givePermissionTo($permissions);
            }

            $this->info("角色「{$roleName}」：更新了 {$roles->count()} 条角色记录。");
        }

        return self::SUCCESS;
    }
}

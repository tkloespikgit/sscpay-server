<?php

namespace Database\Seeders;

use App\Services\PlatformRoleProvisioningService;
use App\Support\Permissions;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (Permissions::all() as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        // 生产用 Redis 缓存权限列表（默认 24h TTL）：老部署上跑本 seeder 新增权限后，
        // 若不清缓存，下面 syncPermissions() 仍会读到不含新权限的旧缓存，抛
        // PermissionDoesNotExist。必须在创建权限记录之后、分配给角色之前清掉。
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 平台级"商户级管理员"角色也在这里种好，必须晚于上面的权限记录创建。
        app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole();
    }
}

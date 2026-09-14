<?php

namespace Database\Seeders;

use App\Services\PlatformRoleProvisioningService;
use App\Support\Permissions;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

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

        // 平台级"商户级管理员"角色也在这里种好，必须晚于上面的权限记录创建。
        app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole();
    }
}

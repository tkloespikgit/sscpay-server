<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SystemConfigSeeder::class,
            PermissionSeeder::class, // 必须在商户角色被建立之前跑，否则 syncPermissions() 找不到权限记录
            CarrierSeeder::class,
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extended spatie/laravel-permission schema.
 *
 * `roles` gets an additional nullable `merchant_id` column:
 *   - NULL  => platform-level (super admin) role
 *   - value => role scoped to a specific merchant
 *
 * The rest of the tables follow spatie/laravel-permission's standard
 * schema so the package's built-in traits/relations work unmodified.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teams = false; // team feature not used; merchant scoping is handled via roles.merchant_id
        $tableNames = config('permission.table_names') ?? [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
        ];
        $columnNames = config('permission.column_names') ?? [
            'model_morph_key' => 'model_id',
        ];

        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->comment('权限名称');
            $table->string('guard_name', 255)->comment('守卫名称');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable()->comment('所属商户（空代表平台级角色）');
            $table->string('name', 255)->comment('角色名称');
            $table->string('guard_name', 255)->comment('守卫名称');
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->nullOnDelete();
            $table->unique(['merchant_id', 'name', 'guard_name']);
            $table->index('merchant_id');
        });

        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 255)->comment('模型类型（如 App\\Models\\User）');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign('permission_id')
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->primary(
                ['permission_id', $columnNames['model_morph_key'], 'model_type'],
                'model_has_permissions_permission_model_type_primary'
            );
        });

        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames) {
            $table->unsignedBigInteger('role_id')->comment('角色 ID');
            $table->string('model_type', 255)->comment('模型类型（如 App\\Models\\User）');
            $table->unsignedBigInteger($columnNames['model_morph_key'])->comment('模型 ID');
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id')
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->primary(
                ['role_id', $columnNames['model_morph_key'], 'model_type'],
                'model_has_roles_role_model_type_primary'
            );
        });

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames) {
            $table->unsignedBigInteger('permission_id')->comment('权限 ID');
            $table->unsignedBigInteger('role_id')->comment('角色 ID');

            $table->foreign('permission_id')
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->foreign('role_id')
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        // 注：这里原先仿照 spatie/laravel-permission 官方迁移模板加了一段
        // 清缓存的代码，但这是"首次建表"的迁移，还没有任何权限数据，
        // 压根没有缓存可清，反而会在 CACHE_STORE 解析成 database 驱动、
        // cache 表还没建出来时把整个迁移炸掉，所以去掉了。真正需要清
        // 权限缓存的地方是后续实际修改角色/权限数据的代码（比如
        // MerchantRoleProvisioningService::syncPermissions() 内部，
        // spatie 包自己会在数据变更时处理，不需要在这里手动清）。
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names') ?? [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
        ];

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
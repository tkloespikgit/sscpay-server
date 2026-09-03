<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * 'roles' 不是 users 表的真实字段，是表单里承载"要赋哪些角色 ID"的
     * 虚拟字段，创建用户前必须先摘掉，否则 User::create() 会因为
     * 多出一个不存在的字段报错（即使 $fillable 里没写它，
     * Eloquent 在某些配置下仍可能尝试写入未知属性）。
     *
     * 密码不需要在这里手动 Hash::make()——User 模型的 casts 里
     * 'password' => 'hashed'，Eloquent 保存时会自动处理。
     */
    protected function handleRecordCreation(array $data): User
    {
        $roleIds = $data['roles'] ?? [];
        unset($data['roles']);

        /** @var User $user */
        $user = static::getModel()::create($data);

        if (! empty($roleIds)) {
            $roles = Role::query()->whereIn('id', $roleIds)->get();
            $user->syncRoles($roles);
        }

        return $user;
    }
}

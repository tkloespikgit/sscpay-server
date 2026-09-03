<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * 表单里的 'roles' 是虚拟字段（不是 users 表的真实列），
     * 编辑页打开时需要手动把当前已有的角色 ID 塞进去，
     * 不然表单会显示"未选择任何角色"，即使这个用户其实已经有角色。
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roles'] = $this->record->roles()->pluck('roles.id')->toArray();

        return $data;
    }

    /**
     * 密码字段留空时，表单层面已经通过 ->dehydrated(fn ($state) => filled($state))
     * 保证 $data 里根本不会带 password 这个 key，所以这里可以放心
     * 直接 $record->update($data)，不会有"把密码覆盖成空字符串"的风险。
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $roleIds = $data['roles'] ?? [];
        unset($data['roles']);

        $record->update($data);

        /** @var User $record */
        $roles = Role::query()->whereIn('id', $roleIds)->get();
        $record->syncRoles($roles);

        return $record;
    }
}

<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\PlatformRoleProvisioningService;
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
     *
     * 'is_merchant_manager' 同样是虚拟字段，根据当前记录反推：
     * merchant_id 为空且不是超管，就是商户级管理员。
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roles'] = $this->record->roles()->pluck('roles.id')->toArray();
        $data['is_merchant_manager'] = $this->record->isMerchantManager();

        return $data;
    }

    /**
     * 密码字段留空时，表单层面已经通过 ->dehydrated(fn ($state) => filled($state))
     * 保证 $data 里根本不会带 password 这个 key，所以这里可以放心
     * 直接 $record->update($data)，不会有"把密码覆盖成空字符串"的风险。
     *
     * 商户级管理员统一赋平台级"商户级管理员"角色，不走"选商户下的角色"那条线，
     * 逻辑同 CreateUser::handleRecordCreation()（包括用 Role 实例而不是名字
     * 字符串去 syncRoles()，避免和商户自定义的同名角色混淆）。
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $roleIds = $data['roles'] ?? [];
        unset($data['roles']);

        $isMerchantManager = (bool) ($data['is_merchant_manager'] ?? false);
        unset($data['is_merchant_manager']);

        if ($isMerchantManager) {
            $data['merchant_id'] = null;
        }

        $record->update($data);

        /** @var User $record */
        if ($isMerchantManager) {
            $record->syncRoles([app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole()]);
        } else {
            $roles = Role::query()->whereIn('id', $roleIds)->get();
            $record->syncRoles($roles);
        }

        return $record;
    }
}

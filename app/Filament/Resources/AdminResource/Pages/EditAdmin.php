<?php

namespace App\Filament\Resources\AdminResource\Pages;

use App\Filament\Resources\AdminResource;
use App\Models\User;
use App\Services\PlatformRoleProvisioningService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * 'account' 是虚拟字段，从已存的 email 反推回填（见 User::accountFromEmail()）。
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['account'] = User::accountFromEmail($this->record->email);

        return $data;
    }

    /**
     * 密码字段留空时，表单层面已经通过 ->dehydrated(fn ($state) => filled($state))
     * 保证 $data 里根本不会带 password 这个 key，可以放心直接 update()。
     *
     * merchant_id 强制维持 NULL；账号类型如果被改过（比如把商户级管理员升级成
     * 超级管理员），需要同步调整角色：超级管理员不需要挂角色（Gate::before 短路），
     * 商户级管理员统一同步成平台共享的那个角色。
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data['merchant_id'] = null;
        $data['email'] = User::emailForAccount($data['account']);
        unset($data['account']);

        $record->update($data);

        /** @var User $record */
        if ($record->is_super_admin) {
            $record->syncRoles([]);
        } else {
            $record->syncRoles([app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole()]);
        }

        return $record;
    }
}

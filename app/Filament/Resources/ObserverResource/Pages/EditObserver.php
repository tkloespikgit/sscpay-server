<?php

namespace App\Filament\Resources\ObserverResource\Pages;

use App\Filament\Resources\ObserverResource;
use App\Models\Observer;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditObserver extends EditRecord
{
    protected static string $resource = ObserverResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * 'account' 是虚拟字段，从已存的 email 反推回填（见 Observer::accountFromEmail()）。
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['account'] = Observer::accountFromEmail($data['email'] ?? null);

        return $data;
    }

    /**
     * 姓名同步账号本身、邮箱按 Observer::emailForAccount() 重新合成，写法同
     * CreateObserver::mutateFormDataBeforeCreate()。
     *
     * 金额显示比例服务端兜底：商户级管理员表单里看不到这个字段，这里保证即使
     * 提交里被篡改带上了这个 key 也不会生效——非超级管理员一律维持记录原值，
     * 不接受任何改动。
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['name'] = $data['account'];
        $data['email'] = Observer::emailForAccount($data['account']);
        unset($data['account']);

        if (! (bool) auth()->user()?->is_super_admin) {
            $data['amount_display_ratio'] = $this->record->amount_display_ratio;
        }

        return $data;
    }
}

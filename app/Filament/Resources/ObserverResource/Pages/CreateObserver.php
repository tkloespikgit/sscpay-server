<?php

namespace App\Filament\Resources\ObserverResource\Pages;

use App\Filament\Resources\ObserverResource;
use App\Models\Observer;
use Filament\Resources\Pages\CreateRecord;

class CreateObserver extends CreateRecord
{
    protected static string $resource = ObserverResource::class;

    /**
     * 'account' 不是 observers 表的真实字段：姓名直接用账号本身（不单独收集），
     * 邮箱是按 Observer::emailForAccount() 合成的内部登录邮箱，不需要真实邮箱。
     *
     * 金额显示比例服务端兜底：表单里商户级管理员根本看不到这个字段
     * （ObserverResource::form() 的 Section->visible()），这里再兜一层，
     * 不管提交里带没带这个 key，非超级管理员一律强制 100（不折算）。
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['name'] = $data['account'];
        $data['email'] = Observer::emailForAccount($data['account']);
        unset($data['account']);

        if (! (bool) auth()->user()?->is_super_admin) {
            $data['amount_display_ratio'] = 100;
        }

        return $data;
    }
}

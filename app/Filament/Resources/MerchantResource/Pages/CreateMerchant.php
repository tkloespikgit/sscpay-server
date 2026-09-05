<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMerchant extends CreateRecord
{
    protected static string $resource = MerchantResource::class;

    /**
     * 商户级管理员创建商户时，表单里根本没有 owner_id 字段（对他们隐藏），
     * 这里强制把归属落到自己名下，否则会建出一个 owner_id 为 NULL、
     * 谁都管不到（连自己都看不到）的孤儿商户。真超管走表单里的 owner_id 选择，
     * 不在这里覆盖。
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user->isMerchantManager()) {
            $data['owner_id'] = $user->id;
        }

        return $data;
    }
}

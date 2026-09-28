<?php

namespace App\Filament\Resources\MerchantDomainResource\Pages;

use App\Filament\Resources\MerchantDomainResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMerchantDomain extends CreateRecord
{
    protected static string $resource = MerchantDomainResource::class;

    /**
     * 创建后直接进编辑页，而不是回列表——新建时 verify_token 还不存在，
     * 「把这条 TXT 记录加到 DNS」的指引只有保存之后才显示得出来，
     * 回列表会让商户完全不知道下一步做什么。
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

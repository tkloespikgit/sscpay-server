<?php

namespace App\Filament\Resources\CheckoutLinkResource\Pages;

use App\Filament\Resources\CheckoutLinkResource;
use App\Filament\Resources\CheckoutLinkResource\Concerns\SyncsCheckoutLinkPaymentGroups;
use App\Filament\Resources\CheckoutLinkResource\Concerns\ValidatesCheckoutLinkAmounts;
use Filament\Resources\Pages\CreateRecord;

class CreateCheckoutLink extends CreateRecord
{
    use SyncsCheckoutLinkPaymentGroups;
    use ValidatesCheckoutLinkAmounts;

    protected static string $resource = CheckoutLinkResource::class;

    /**
     * 创建后进编辑页而不是回列表：商户建完链接的下一步一定是复制地址，
     * 而 slug 是保存时才生成的（见 CheckoutLink::booted），列表页要多点一次才看得到。
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->storePaymentGroups($data, (int) ($data['merchant_id'] ?? 0));
    }
}

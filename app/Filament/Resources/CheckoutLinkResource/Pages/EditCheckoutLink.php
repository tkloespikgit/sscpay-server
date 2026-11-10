<?php

namespace App\Filament\Resources\CheckoutLinkResource\Pages;

use App\Filament\Resources\CheckoutLinkResource;
use App\Filament\Resources\CheckoutLinkResource\Concerns\SyncsCheckoutLinkPaymentGroups;
use App\Filament\Resources\CheckoutLinkResource\Concerns\ValidatesCheckoutLinkAmounts;
use App\Services\Checkout\CheckoutLinkGroupSelection;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCheckoutLink extends EditRecord
{
    use SyncsCheckoutLinkPaymentGroups;
    use ValidatesCheckoutLinkAmounts;

    protected static string $resource = CheckoutLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['paymentGroups'] = app(CheckoutLinkGroupSelection::class)->fromStored(
            $data['payment_group_id'] ?? null,
            $data['payment_group_ids'] ?? null,
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['merchant_id']);

        return $this->storePaymentGroups($data, (int) $this->getRecord()->merchant_id);
    }
}

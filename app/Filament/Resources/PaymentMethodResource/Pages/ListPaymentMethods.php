<?php

namespace App\Filament\Resources\PaymentMethodResource\Pages;

use App\Filament\Resources\PaymentMethodResource;
use App\Filament\Support\StaticConfigShowcaseAction;
use App\Models\Merchant;
use App\Models\User;
use App\Support\StaticConfigShowcase;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Computed;

class ListPaymentMethods extends ListRecords
{
    protected static string $resource = PaymentMethodResource::class;

    #[Computed]
    public function financialMerchant(): ?Merchant
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return null;
        }

        $value = $actor->isPlatformStaff()
            ? ($this->getTableFilterState('merchant_id')['value'] ?? null)
            : $actor->merchant_id;

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $merchantId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($merchantId === false) {
            return null;
        }

        $query = Merchant::query()->whereKey($merchantId)->where('status', true);
        $merchantIds = $actor->manageableMerchantIds();

        if ($merchantIds !== null) {
            $query->whereIn('id', $merchantIds);
        }

        return $query->first();
    }

    public function hasInvalidFinancialMerchantFilter(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isPlatformStaff()
            && filled($this->getTableFilterState('merchant_id')['value'] ?? null)
            && $this->financialMerchant === null;
    }

    protected function handleTableFilterUpdates(): void
    {
        unset($this->financialMerchant);

        parent::handleTableFilterUpdates();
    }

    protected function getHeaderActions(): array
    {
        return [
            StaticConfigShowcaseAction::make(StaticConfigShowcase::PAYMENT),
            CreateAction::make(),
        ];
    }
}

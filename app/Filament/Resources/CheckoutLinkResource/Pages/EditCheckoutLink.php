<?php

namespace App\Filament\Resources\CheckoutLinkResource\Pages;

use App\Filament\Resources\CheckoutLinkResource;
use App\Filament\Resources\CheckoutLinkResource\Concerns\ValidatesCheckoutLinkAmounts;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCheckoutLink extends EditRecord
{
    use ValidatesCheckoutLinkAmounts;

    protected static string $resource = CheckoutLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

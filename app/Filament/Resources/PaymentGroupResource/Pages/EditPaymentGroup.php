<?php

namespace App\Filament\Resources\PaymentGroupResource\Pages;

use App\Filament\Resources\PaymentGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentGroup extends EditRecord
{
    protected static string $resource = PaymentGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

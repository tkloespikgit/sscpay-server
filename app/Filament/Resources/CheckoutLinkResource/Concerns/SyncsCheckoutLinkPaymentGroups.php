<?php

namespace App\Filament\Resources\CheckoutLinkResource\Concerns;

use App\Models\User;
use App\Services\Checkout\CheckoutLinkGroupSelection;
use Illuminate\Validation\ValidationException;

trait SyncsCheckoutLinkPaymentGroups
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function storePaymentGroups(array $data, int $merchantId): array
    {
        $selection = $data['paymentGroups'] ?? [];
        unset($data['paymentGroups']);

        $actor = auth()->user();
        $service = app(CheckoutLinkGroupSelection::class);
        $message = $service->message($actor instanceof User ? $actor : null, $merchantId, $selection);

        if ($message !== null) {
            throw ValidationException::withMessages([
                'data.paymentGroups' => $message,
            ]);
        }

        return [
            ...$data,
            ...$service->columns(is_array($selection) ? $selection : []),
        ];
    }
}

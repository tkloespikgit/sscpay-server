<?php

namespace App\Filament\Resources\PaymentGroupResource\Pages;

use App\Filament\Resources\PaymentGroupResource;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\PaymentGroupMethodService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreatePaymentGroup extends CreateRecord
{
    protected static string $resource = PaymentGroupResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): PaymentGroup
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $members = $data['paymentMethods'] ?? [];
        unset($data['paymentMethods']);

        try {
            if (! is_array($members)) {
                throw ValidationException::withMessages([
                    'paymentMethods' => __('validation.array', ['attribute' => __('admin.payment_group.fields.payment_methods')]),
                ]);
            }

            return app(PaymentGroupMethodService::class)->createGroup($actor, $data, $members);
        } catch (ValidationException $exception) {
            $statePath = $this->form->getStatePath();

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [
                    filled($statePath) ? $statePath.'.'.$field : $field => $messages,
                ])->all());
        }
    }
}

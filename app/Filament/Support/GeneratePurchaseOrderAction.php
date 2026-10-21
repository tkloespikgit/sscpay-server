<?php

namespace App\Filament\Support;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class GeneratePurchaseOrderAction
{
    public static function canGenerate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin && $user->status;
    }

    public static function make(): Action
    {
        return Action::make('generatePurchaseOrder')
            ->label(__('purchase_order.generate'))
            ->icon('heroicon-o-document-plus')
            ->visible(fn (): bool => self::canGenerate())
            ->authorize(fn (): bool => self::canGenerate())
            ->modalHeading(__('purchase_order.generate'))
            ->modalDescription(__('purchase_order.generation_help'))
            ->schema([
                DateTimePicker::make('ordered_at')
                    ->label(__('purchase_order.fields.ordered_at'))
                    ->default(now())
                    ->required()
                    ->columnSpanFull(),
                ...self::chargeFields(),
            ])
            ->action(function (array $data, PaymentMethod $record, PurchaseOrderService $service, Schema $schema, Component $livewire): void {
                abort_unless(self::canGenerate(), 403);

                try {
                    $order = $service->generate($record, auth()->user(), $data);
                } catch (ValidationException $exception) {
                    self::throwSchemaValidationException($exception, $schema);
                }

                Notification::make()
                    ->success()
                    ->title(__('purchase_order.generated', ['number' => $order->order_no]))
                    ->send();

                $livewire->dispatch('purchase-order-generated.'.$record->getKey());
            });
    }

    /** @return array<TextInput> */
    public static function chargeFields(bool $required = false): array
    {
        return array_map(function (string $field) use ($required): TextInput {
            return TextInput::make($field)
                ->label(__('purchase_order.fields.'.$field))
                ->prefix('¥')
                ->numeric()
                ->minValue(0)
                ->rules(['decimal:0,2'])
                ->required($required)
                ->placeholder($required ? null : __('purchase_order.automatic'))
                ->helperText($required ? null : __('purchase_order.'.$field.'_help'));
        }, ['shipping_fee', 'discount']);
    }

    public static function throwSchemaValidationException(ValidationException $exception, Schema $schema): never
    {
        throw ValidationException::withMessages(collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $field): array => [
                $schema->getStatePath().'.'.$field => $messages,
            ])->all());
    }
}

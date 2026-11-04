<?php

namespace App\Filament\Resources\PaymentGroupResource\Pages;

use App\Filament\Resources\PaymentGroupResource;
use App\Filament\Resources\PaymentGroupResource\RelationManagers\PaymentMethodsRelationManager;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Services\PaymentGroupMethodService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditPaymentGroup extends EditRecord
{
    protected static string $resource = PaymentGroupResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 1, 'xl' => 12])
            ->components([
                $this->getFormContentComponent()
                    ->columnSpan(['default' => 1, 'xl' => 5]),
                $this->getRelationManagersContentComponent()
                    ->columnSpan(['default' => 1, 'xl' => 7]),
            ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $current = PaymentGroup::query()->withoutGlobalScope(MerchantScope::class)->findOrFail($record->getKey());
            $merchant = Merchant::query()
                ->whereKey($current->merchant_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = PaymentGroup::query()->withoutGlobalScope(MerchantScope::class)
                ->lockForUpdate()->findOrFail($record->getKey());
            $actor = User::query()->find(auth()->id());

            abort_unless(PaymentGroupMethodService::canManage($actor, $locked), 403);
            abort_unless((int) $locked->merchant_id === (int) $current->merchant_id, 409);
            abort_unless($actor->is_super_admin
                || ($actor->isMerchantManager() && (int) $merchant->owner_id === (int) $actor->id)
                || (! $actor->isPlatformStaff() && (int) $merchant->id === (int) $actor->merchant_id), 403);

            if (array_key_exists('merchant_id', $data) && (int) $data['merchant_id'] !== (int) $locked->merchant_id) {
                throw ValidationException::withMessages([
                    'data.merchant_id' => __('admin.payment_group.validation.merchant_immutable'),
                ]);
            }

            unset($data['merchant_id']);
            $this->record = $locked;

            return parent::handleRecordUpdate($locked, $data);
        }, 3);
    }

    protected function afterSave(): void
    {
        $this->dispatch('payment-group-updated')->to(PaymentMethodsRelationManager::class);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

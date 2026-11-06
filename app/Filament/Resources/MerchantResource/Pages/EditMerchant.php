<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantResource;
use App\Filament\Resources\MerchantResource\RelationManagers\PaymentSettingsRelationManager;
use App\Filament\Support\StaticConfigShowcaseAction;
use App\Models\User;
use App\Services\MerchantPaymentMethodAssignmentService;
use App\Services\MerchantPaymentSettingsService;
use App\Support\StaticConfigShowcase;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

class EditMerchant extends EditRecord
{
    protected static string $resource = MerchantResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'xl' => 12])
            ->components([
                $this->getFormContentComponent()
                    ->columnSpan(['default' => 1, 'xl' => 5]),
                $this->getRelationManagersContentComponent()
                    ->columnSpan(['default' => 1, 'xl' => 7]),
            ]);
    }

    protected function afterSave(): void
    {
        app(MerchantPaymentSettingsService::class)->initializeMissingForMerchant((int) $this->record->getKey());
    }

    protected function getHeaderActions(): array
    {
        return [
            StaticConfigShowcaseAction::make(StaticConfigShowcase::MERCHANT),
            $this->getAuthorizePaymentMethodsAction(),
            DeleteAction::make(), // 软删除，关联数据保留（4.1 节）
        ];
    }

    protected function getAuthorizePaymentMethodsAction(): Action
    {
        $canManage = fn (): bool => MerchantPaymentMethodAssignmentService::canManage(auth()->user(), $this->record);

        return Action::make('authorizePaymentMethods')
            ->label(__('admin.merchant.payment_authorization.action'))
            ->icon('heroicon-o-credit-card')
            ->visible($canManage)
            ->authorize($canManage)
            ->modalHeading(__('admin.merchant.payment_authorization.action'))
            ->modalDescription(__('admin.merchant.payment_authorization.description'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalContent(function (MerchantPaymentMethodAssignmentService $service) use ($canManage): View {
                abort_unless($canManage(), 403);

                $actor = auth()->user();
                $methods = $service->methods($actor, $this->record);

                return view('filament.resources.merchant-resource.payment-method-authorization', [
                    'columns' => [
                        'unassigned' => $methods->where('is_assigned', false),
                        'assigned' => $methods->where('is_assigned', true),
                    ],
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('admin.merchant.payment_authorization.close'));
    }

    public function setPaymentMethodAuthorization(int $paymentMethodId, bool $authorized, MerchantPaymentMethodAssignmentService $service): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $service->setAssignment($actor, $this->record, $paymentMethodId, $authorized);

        $this->dispatch('merchant-payment-methods-updated')->to(PaymentSettingsRelationManager::class);
    }
}

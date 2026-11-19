<?php

namespace App\Filament\Support;

use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\WooCommerceAuthorizationService;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;

trait InteractsWithWooCommerceAuthorization
{
    #[Locked]
    public ?string $wooCommerceAuthorizationToken = null;

    #[Locked]
    public ?string $wooCommerceAuthorizationUrl = null;

    #[Locked]
    public ?string $wooCommerceAuthorizationFormHash = null;

    public function startWooCommerceAuthorization(WooCommerceAuthorizationService $service): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && ($this->data['payment_type'] ?? 'fiat') === 'fiat', 403);

        if ($this->wooCommerceAuthorizationToken !== null) {
            $service->cancel($actor, $this->wooCommerceAuthorizationToken);
        }
        $this->resetWooCommerceAuthorization();

        $authorization = $service->begin($actor, (string) ($this->data['domain'] ?? ''), $this->wooCommercePaymentMethod());
        $this->wooCommerceAuthorizationToken = $authorization['token'];
        $this->wooCommerceAuthorizationUrl = $authorization['url'];
        $this->wooCommerceAuthorizationFormHash = $this->wooCommerceFormHash();
    }

    public function refreshWooCommerceAuthorization(WooCommerceAuthorizationService $service): void
    {
        if ($this->wooCommerceAuthorizationToken === null) {
            return;
        }

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        if ($this->wooCommerceAuthorizationFormHash !== $this->wooCommerceFormHash()) {
            $service->cancel($actor, $this->wooCommerceAuthorizationToken);
            $result = ['status' => 'changed'];
        } else {
            $result = $service->consume($actor, $this->wooCommerceAuthorizationToken,
                (string) ($this->data['domain'] ?? ''), $this->wooCommercePaymentMethod());
        }

        if ($result['status'] === 'pending') {
            return;
        }

        $this->resetWooCommerceAuthorization();
        $this->unmountAction();

        if ($result['status'] === 'ready') {
            $this->data['domain_client_id'] = $result['credentials']['consumer_key'];
            $this->data['domain_client_sk'] = $result['credentials']['consumer_secret'];
            Notification::make()->title(__('admin.payment_method.woocommerce.ready'))->success()->send();

            return;
        }

        Notification::make()->title(__('admin.payment_method.woocommerce.'.$result['status']))->warning()->send();
    }

    private function wooCommercePaymentMethod(): ?PaymentMethod
    {
        return $this instanceof EditPaymentMethod ? $this->getRecord() : null;
    }

    private function wooCommerceFormHash(): string
    {
        return hash('sha256', json_encode([
            $this->data['payment_type'] ?? 'fiat',
            rtrim(trim((string) ($this->data['domain'] ?? '')), '/'),
            $this->data['domain_client_id'] ?? null,
            $this->data['domain_client_sk'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function resetWooCommerceAuthorization(): void
    {
        $this->wooCommerceAuthorizationToken = null;
        $this->wooCommerceAuthorizationUrl = null;
        $this->wooCommerceAuthorizationFormHash = null;
    }
}

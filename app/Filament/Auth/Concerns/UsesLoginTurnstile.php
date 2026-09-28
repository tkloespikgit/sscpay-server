<?php

namespace App\Filament\Auth\Concerns;

use App\Services\Checkout\TurnstileService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

trait UsesLoginTurnstile
{
    public ?string $turnstileToken = null;

    public function form(Schema $schema): Schema
    {
        $components = [
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
        ];

        if (app(TurnstileService::class)->isConfigured()) {
            $components[] = View::make('filament.auth.login-turnstile');
        }

        return $schema->components($components);
    }

    public function authenticate(): ?LoginResponse
    {
        // A completed first step already passed Turnstile. Filament handles the
        // subsequent MFA challenge and its own rate limit in the parent method.
        if (app(TurnstileService::class)->isConfigured() && blank($this->userUndertakingMultiFactorAuthentication)) {
            try {
                if (! app(TurnstileService::class)->verify($this->turnstileToken, request()->ip())) {
                    throw ValidationException::withMessages([
                        'turnstileToken' => __('admin.auth.turnstile_failed'),
                    ]);
                }
            } finally {
                // Tokens are single-use. A failed password attempt needs a fresh
                // widget response, otherwise the next attempt always fails.
                $this->turnstileToken = null;
                $this->dispatch('login-turnstile-reset');
            }
        }

        return parent::authenticate();
    }
}

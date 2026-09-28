<div>
    <div wire:ignore>
        <div class="cf-turnstile" data-sitekey="{{ app(\App\Services\Checkout\TurnstileService::class)->siteKey() }}"
             data-callback="checkoutLoginTurnstileCompleted"
             data-expired-callback="checkoutLoginTurnstileCleared"
             data-error-callback="checkoutLoginTurnstileCleared"
             data-theme="auto"></div>
    </div>
    @error('turnstileToken')
        <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror
</div>

@once
    <script>
        window.checkoutLoginTurnstileComponent = function () {
            const widget = document.querySelector('.cf-turnstile');
            const root = widget?.closest('[wire\\:id]');
            return root ? window.Livewire?.find(root.getAttribute('wire:id')) : null;
        };
        window.checkoutLoginTurnstileCompleted = function (token) {
            window.checkoutLoginTurnstileComponent()?.set('turnstileToken', token);
        };
        window.checkoutLoginTurnstileCleared = function () {
            window.checkoutLoginTurnstileComponent()?.set('turnstileToken', null);
        };
        window.addEventListener('login-turnstile-reset', function () {
            window.turnstile?.reset();
        });
    </script>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endonce

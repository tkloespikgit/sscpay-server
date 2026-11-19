<div wire:poll.3s="refreshWooCommerceAuthorization">
    <p>{{ __('admin.payment_method.woocommerce.waiting') }}</p>
    <p>
        <x-filament::button :href="$authorizationUrl" tag="a" target="_blank" rel="noopener noreferrer">
            {{ __('admin.payment_method.woocommerce.open') }}
        </x-filament::button>
    </p>
    <p>{{ __('admin.payment_method.woocommerce.requirements') }}</p>
</div>

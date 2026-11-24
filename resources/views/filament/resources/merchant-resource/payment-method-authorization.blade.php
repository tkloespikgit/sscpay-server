@php
    $panelKey = $panelKey ?? 'payment-authorization';
    $selectionAction = $selectionAction ?? 'setPaymentMethodAuthorization';
    $selectionLabels = $selectionLabels ?? 'admin.merchant.payment_authorization';
    $lockMerchantOwned = $lockMerchantOwned ?? true;
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr)); gap: 1rem;">
    @foreach ($columns as $side => $methods)
        <x-filament::section wire:key="{{ $panelKey }}-{{ $side }}">
            <x-slot name="heading">
                {{ __($selectionLabels . '.' . $side) }} ({{ $methods->count() }})
            </x-slot>

            <div x-data="{ search: '' }" style="display: grid; gap: 0.75rem;">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="search"
                        x-model="search"
                        x-on:keydown.enter.prevent
                        :placeholder="__($selectionLabels . '.search')"
                        :aria-label="__('admin.merchant.payment_authorization.search_' . $side)"
                    />
                </x-filament::input.wrapper>

                <div role="list" style="display: grid; align-content: start; gap: 0.5rem; min-height: 15rem; max-height: 26rem; overflow-y: auto; padding: 0.25rem;">
                    @forelse ($methods as $method)
                        @php
                            $merchantOwned = $lockMerchantOwned && ! $method->isSystemLevel();
                            $authorize = $side === 'unassigned';
                        @endphp

                        <div
                            role="listitem"
                            wire:key="{{ $panelKey }}-{{ $side }}-{{ $method->id }}"
                            x-show="@js(mb_strtolower($method->method_name . ' ' . $method->method_code)).includes(search.trim().toLowerCase())"
                        >
                            <x-filament::button
                                type="button"
                                color="gray"
                                outlined
                                :disabled="$merchantOwned"
                                :wire:click="$merchantOwned ? null : $selectionAction . '(' . $method->id . ', ' . ($authorize ? 'true' : 'false') . ')'"
                                wire:loading.attr="disabled"
                                wire:target="{{ $selectionAction }}"
                                :aria-label="__($selectionLabels . '.' . ($merchantOwned ? 'merchant_owned_named' : ($authorize ? 'authorize_named' : 'revoke_named')), ['name' => $method->method_name])"
                                style="display: flex; width: 100%; text-align: start; white-space: normal;"
                            >
                                <span style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; width: 100%; min-width: 0;">
                                    <span style="display: grid; gap: 0.25rem; min-width: 0; overflow-wrap: anywhere;">
                                        <span>{{ $method->method_name }}</span>
                                        <span style="font-size: 0.75rem; opacity: 0.7;">{{ $method->method_code }}</span>
                                        @if (! $method->is_active)
                                            <span style="font-size: 0.75rem; opacity: 0.7;">{{ __('admin.merchant.payment_authorization.inactive') }}</span>
                                        @endif
                                    </span>
                                    <span style="flex-shrink: 0; max-width: 45%; font-size: 0.75rem; overflow-wrap: anywhere;">
                                        {{ __($selectionLabels . '.' . ($merchantOwned ? 'merchant_owned' : ($authorize ? 'authorize' : 'revoke'))) }}
                                    </span>
                                </span>
                            </x-filament::button>
                        </div>
                    @empty
                        <p style="padding: 1.5rem 0.5rem; text-align: center; font-size: 0.875rem; opacity: 0.7;">
                            {{ __('admin.merchant.payment_authorization.empty_' . $side) }}
                        </p>
                    @endforelse

                    @if ($methods->isNotEmpty())
                        <p
                            x-cloak
                            x-show="! @js($methods->map(fn ($method) => mb_strtolower($method->method_name . ' ' . $method->method_code))->values()->all()).some(name => name.includes(search.trim().toLowerCase()))"
                            style="padding: 1.5rem 0.5rem; text-align: center; font-size: 0.875rem; opacity: 0.7;"
                        >
                            {{ __($selectionLabels . '.no_results') }}
                        </p>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endforeach
</div>

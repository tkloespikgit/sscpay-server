<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr)); gap: 1rem;">
    @foreach ($columns as $side => $merchants)
        <x-filament::section wire:key="merchant-authorization-{{ $side }}">
            <x-slot name="heading">
                {{ __('admin.payment_method.authorization.' . $side) }} ({{ $merchants->count() }})
            </x-slot>

            <div x-data="{ search: '' }" style="display: grid; gap: 0.75rem;">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="search"
                        x-model="search"
                        x-on:keydown.enter.prevent
                        :placeholder="__('admin.payment_method.authorization.search')"
                        :aria-label="__('admin.payment_method.authorization.search_' . $side)"
                    />
                </x-filament::input.wrapper>

                <div role="list" style="display: grid; align-content: start; gap: 0.5rem; min-height: 15rem; max-height: 26rem; overflow-y: auto; padding: 0.25rem;">
                    @forelse ($merchants as $merchant)
                        @php
                            $authorize = $side === 'unassigned';
                        @endphp

                        <div
                            role="listitem"
                            wire:key="merchant-authorization-{{ $side }}-{{ $merchant->id }}"
                            x-show="@js(mb_strtolower($merchant->name . ' ' . (string) $merchant->id)).includes(search.trim().toLowerCase())"
                        >
                            <x-filament::button
                                type="button"
                                color="gray"
                                outlined
                                :wire:click="'setMerchantAuthorization(' . $merchant->id . ', ' . ($authorize ? 'true' : 'false') . ')'"
                                wire:loading.attr="disabled"
                                wire:target="setMerchantAuthorization"
                                :aria-label="__('admin.payment_method.authorization.' . ($authorize ? 'authorize_named' : 'revoke_named'), ['name' => $merchant->name])"
                                style="display: flex; width: 100%; text-align: start; white-space: normal;"
                            >
                                <span style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; width: 100%; min-width: 0;">
                                    <span style="display: grid; gap: 0.25rem; min-width: 0; overflow-wrap: anywhere;">
                                        <span>{{ $merchant->name }}</span>
                                        <span style="font-size: 0.75rem; opacity: 0.7;">{{ (string) $merchant->id }}</span>
                                    </span>
                                    <span style="flex-shrink: 0; max-width: 45%; font-size: 0.75rem; overflow-wrap: anywhere;">
                                        {{ __('admin.payment_method.authorization.' . ($authorize ? 'authorize' : 'revoke')) }}
                                    </span>
                                </span>
                            </x-filament::button>
                        </div>
                    @empty
                        <p style="padding: 1.5rem 0.5rem; text-align: center; font-size: 0.875rem; opacity: 0.7;">
                            {{ __('admin.payment_method.authorization.empty_' . $side) }}
                        </p>
                    @endforelse

                    @if ($merchants->isNotEmpty())
                        <p
                            x-cloak
                            x-show="! @js($merchants->map(fn ($merchant) => mb_strtolower($merchant->name . ' ' . (string) $merchant->id))->values()->all()).some(name => name.includes(search.trim().toLowerCase()))"
                            style="padding: 1.5rem 0.5rem; text-align: center; font-size: 0.875rem; opacity: 0.7;"
                        >
                            {{ __('admin.payment_method.authorization.no_results') }}
                        </p>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endforeach
</div>

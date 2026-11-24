<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 17rem), 1fr)); gap: 1rem;">
    @foreach ($services as $service => $label)
        @php($enabled = in_array($service, $account->services ?? [], true))
        <x-filament::section :heading="$provider . ' · ' . $label" wire:key="chargeback-service-{{ $service }}">
            <x-slot name="afterHeader">
                <x-filament::badge :color="$enabled ? ($account->enabled ? 'success' : 'warning') : 'gray'">
                    {{ __('chargeback_alerts.' . ($enabled ? ($account->enabled ? 'service_on' : 'connection_paused') : 'service_off')) }}
                </x-filament::badge>
            </x-slot>

            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                <x-filament::button type="button" color="gray" outlined
                    :wire:click="'setChargebackServiceEnabled(' . \Illuminate\Support\Js::from($service) . ', ' . ($enabled ? 'false' : 'true') . ')'">
                    {{ __('chargeback_alerts.' . ($enabled ? 'stop_service' : 'start_service')) }}
                </x-filament::button>
                <x-filament::button type="button" icon="heroicon-o-credit-card"
                    :wire:click="'mountAction(\'bindPaymentMethods\', { service: ' . \Illuminate\Support\Js::from($service) . ' })'">
                    {{ __('chargeback_alerts.bind_methods') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endforeach
</div>

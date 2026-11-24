<div>
    @error('chargebackBinding')
        <p role="alert" style="color: var(--danger-600); margin-bottom: 0.75rem;">{{ $message }}</p>
    @enderror

    @if ($columns !== null)
        @include('filament.resources.merchant-resource.payment-method-authorization', [
            'columns' => $columns,
            'panelKey' => 'chargeback-channel-binding',
            'selectionAction' => 'setChargebackChannelBinding',
            'selectionLabels' => 'chargeback_alerts.binding_panel',
            'lockMerchantOwned' => false,
        ])
    @else
        <p>{{ __('chargeback_alerts.choose_scope') }}</p>
    @endif
</div>

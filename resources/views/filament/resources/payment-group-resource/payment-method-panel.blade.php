<div x-data="{ search: { available: '', included: '' } }" style="display: grid; gap: 1rem; padding: 1.5rem;">
    <x-filament::section
        :heading="__('admin.payment_group.method_panel.title')"
        :description="__('admin.payment_group.method_panel.description')"
    >
        @include('filament.resources.payment-group-resource.method-panel-lists', [
            'draft' => false,
            'statePath' => null,
            'selectionState' => [],
            'merchantId' => null,
        ])
    </x-filament::section>

    <p style="font-size: 0.875rem; opacity: 0.7;">{{ __('admin.payment_group.relation_manager.load_snapshot_help') }}</p>
</div>

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div wire:key="draft-group-methods-{{ $merchantId ?? 'none' }}">
        <x-filament::section
            :heading="__('admin.payment_group.method_panel.title')"
            :description="__('admin.payment_group.method_panel.create_description')"
        >
            @if ($merchantId)
                @include('filament.resources.payment-group-resource.method-panel-lists', [
                    'draft' => true,
                    'columns' => ['available' => $methods, 'included' => $methods],
                    'statePath' => $getStatePath(),
                    'selectionState' => $getState() ?? [],
                ])
            @else
                <p style="padding: 2rem 1rem; text-align: center; font-size: 0.875rem; opacity: 0.65;">
                    {{ __('admin.payment_group.method_panel.choose_merchant') }}
                </p>
            @endif
        </x-filament::section>
    </div>
</x-dynamic-component>

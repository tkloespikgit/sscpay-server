<x-filament::section>
    <x-slot name="heading">
        {{ __('admin.dashboard.filters.period') }}
    </x-slot>

    <x-slot name="description">
        {{ $this->getDateRangeLabel() }}
    </x-slot>

    <x-filament::tabs :label="__('admin.dashboard.filters.period')">
        @foreach ($this->getPeriodOptions() as $period => $label)
            <x-filament::tabs.item
                :active="$this->filters['period'] === $period"
                wire:click="selectPeriod('{{ $period }}')"
                wire:loading.attr="disabled"
                wire:target="selectPeriod"
                wire:key="dashboard-period-{{ $period }}"
            >
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach

        <x-filament::tabs.item
            :active="$this->filters['period'] === 'custom'"
            wire:click="mountAction('customDateRange')"
            wire:loading.attr="disabled"
            wire:target="mountAction"
        >
            {{ __('admin.dashboard.periods.custom') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    <x-slot name="footer">
        {{ __('admin.dashboard.stats.data_note') }}
    </x-slot>
</x-filament::section>

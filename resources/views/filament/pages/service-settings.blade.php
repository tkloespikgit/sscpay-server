<x-filament-panels::page>
    {{ $this->merchantForm }}

    @if ($this::canManageLogisticsTracking())
        <form wire:submit="saveLogisticsTracking">
            {{ $this->logisticsForm }}
        </form>
    @endif

    @if ($this::canManageTelegram())
        <form wire:submit="saveTelegram">
            {{ $this->telegramForm }}
        </form>
    @endif
</x-filament-panels::page>

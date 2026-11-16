<x-filament-panels::page>
    <x-filament::tabs :label="__('admin.telegram_routes.title')">
        @foreach (\App\Support\TelegramNotificationKind::grouped() as $group => $kinds)
            <x-filament::tabs.item
                :active="$notificationGroup === $group"
                :badge="count($kinds)"
                wire:click="setNotificationGroup('{{ $group }}')"
            >
                {{ __('admin.telegram_routes.groups.'.$group) }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>

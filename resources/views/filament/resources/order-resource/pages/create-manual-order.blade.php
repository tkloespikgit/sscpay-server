<x-filament-panels::page>
    <form wire:submit="create">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit">
                创建并发送付款链接
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

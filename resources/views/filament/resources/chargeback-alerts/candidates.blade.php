<x-filament::section>
    <x-slot name="heading">
        {{ __($matched ? 'chargeback_alerts.matched_order' : 'chargeback_alerts.candidate_orders') }}
    </x-slot>

    @if (! $matched)
        <p>{{ __('chargeback_alerts.candidate_help') }}</p>
    @endif

    <ul role="list" style="display: grid; gap: 0.75rem; margin-top: 0.75rem;">
        @forelse ($orders as $order)
            <li style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
                @if ($order['url'])
                    <x-filament::link :href="$order['url']" target="_blank" rel="noopener">{{ $order['number'] }}</x-filament::link>
                @else
                    <span>{{ $order['number'] }}</span>
                @endif
                <span>{{ $order['amount'] }}</span>
                <span>{{ $order['time'] }}</span>
            </li>
        @empty
            <li>{{ __('chargeback_alerts.no_candidates') }}</li>
        @endforelse
    </ul>
</x-filament::section>

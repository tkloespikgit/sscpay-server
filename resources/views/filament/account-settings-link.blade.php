@php
    $url = filament()->getProfileUrl();
    $active = filled($url) && request()->routeIs('filament.'.filament()->getCurrentPanel()->getId().'.auth.profile');
@endphp

@if (filled($url))
    <span @class(['fi-topbar-item', 'fi-active' => $active])>
        <a
            href="{{ $url }}"
            @class(['fi-topbar-item-btn'])
            @if ($active) aria-current="page" @endif
        >
            <span class="fi-topbar-item-label">
                {{ __('admin.account_settings.label') }}
            </span>
        </a>
    </span>
@endif

@once
    <style>
        .cl-group-panel { min-width: 0; container-type: inline-size; }
        .cl-group-columns { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        @container (min-width: 32rem) {
            .cl-group-columns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .cl-group-column { min-width: 0; overflow: hidden; border: 1px solid color-mix(in srgb, currentColor 13%, transparent); border-radius: 0.75rem; }
        .cl-group-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.875rem 1rem; background: color-mix(in srgb, currentColor 3%, transparent); }
        .cl-group-title { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 600; }
        .cl-group-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.5rem; padding: 0.1rem 0.4rem; border-radius: 0.375rem; background: color-mix(in srgb, currentColor 7%, transparent); font-size: 0.75rem; font-variant-numeric: tabular-nums; }
        .cl-group-search { padding: 0.75rem 0.875rem; }
        .cl-group-list { min-height: 12rem; max-height: 22rem; overflow-y: auto; padding: 0 0.5rem 0.5rem; }
        .cl-group-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 0.75rem; width: 100%; min-height: 3.5rem; padding: 0.625rem 0.5rem; border: 0; border-radius: 0.5rem; background: transparent; text-align: start; }
        .cl-group-row-available { cursor: pointer; }
        .cl-group-row:hover { background: color-mix(in srgb, currentColor 3%, transparent); }
        .cl-group-name { min-width: 0; }
        .cl-group-name-line { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.875rem; font-weight: 500; }
        .cl-group-meta { display: flex; gap: 0.5rem; min-width: 0; padding-top: 0.2rem; font-size: 0.6875rem; opacity: 0.6; }
        .cl-group-code { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .cl-group-add, .cl-group-remove, .cl-group-default { display: inline-flex; align-items: center; justify-content: center; height: 2rem; border: 1px solid color-mix(in srgb, currentColor 12%, transparent); border-radius: 0.5rem; background: transparent; }
        .cl-group-add, .cl-group-remove { width: 2rem; font-size: 1.125rem; line-height: 1; }
        .cl-group-add { color: var(--primary-600, currentColor); }
        .cl-group-remove { cursor: pointer; opacity: 0.65; }
        .cl-group-remove:hover { color: var(--danger-600, currentColor); opacity: 1; }
        .cl-group-default { gap: 0.35rem; padding: 0 0.6rem; cursor: pointer; font-size: 0.75rem; }
        .cl-group-default[aria-pressed="true"] { border-color: var(--primary-600, currentColor); color: var(--primary-600, currentColor); }
        .cl-group-empty { padding: 2.25rem 1rem; text-align: center; font-size: 0.8125rem; opacity: 0.6; }
        .cl-group-list > [role="listitem"] + [role="listitem"] { border-top: 1px solid color-mix(in srgb, currentColor 7%, transparent); }
    </style>
@endonce

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="cl-group-panel" wire:key="checkout-link-groups-{{ $merchantId ?? 'none' }}">
        <x-filament::section
            :heading="__('admin.checkout_link.group_panel.title')"
            :description="__('admin.checkout_link.group_panel.description')"
        >
            @if ($merchantId)
                @php
                    $panelItems = $groups->map(fn ($group): array => [
                        'id' => (int) $group->id,
                        'active' => (bool) $group->is_active,
                        'deleted' => $group->trashed(),
                        'search' => mb_strtolower($group->group_name.' '.$group->group_key),
                    ])->values()->all();
                @endphp
                <div
                    x-data="{
                        items: @js($panelItems),
                        selection: $wire.$entangle(@js($getStatePath())),
                        search: { available: '', included: '' },
                        rows() { return Array.isArray(this.selection) ? this.selection : [] },
                        selected(id) { return this.rows().some(item => item && Number(item.id) === Number(id)) },
                        isDefault(id) { return this.rows().some(item => item && Number(item.id) === Number(id) && item.is_default) },
                        matches(side, text) { return text.includes(this.search[side].trim().toLowerCase()) },
                        visible(side, item) {
                            if (!item) return false;
                            const included = this.selected(item.id);
                            const onAvailableSide = !included && item.active && !item.deleted;
                            return (side === 'included' ? included : onAvailableSide) && this.matches(side, item.search);
                        },
                        count(side) { return this.items.filter(item => this.visible(side, item)).length },
                        add(id) {
                            if (this.selected(id)) return;
                            const rows = this.rows();
                            this.selection = [...rows, { id, is_default: rows.length === 0 }];
                        },
                        remove(id) {
                            let rows = this.rows().filter(item => item && Number(item.id) !== Number(id));
                            if (rows.length && !rows.some(item => item.is_default)) rows = rows.map((item, index) => ({ ...item, is_default: index === 0 }));
                            this.selection = rows;
                        },
                        makeDefault(id) {
                            this.selection = this.rows().map(item => item ? { ...item, is_default: Number(item.id) === Number(id) } : item);
                        },
                    }"
                >
                    <div class="cl-group-columns">
                        @foreach (['available', 'included'] as $side)
                            <section class="cl-group-column" aria-label="{{ __('admin.checkout_link.group_panel.'.$side) }}">
                                <div class="cl-group-head">
                                    <h3 class="cl-group-title">
                                        {{ __('admin.checkout_link.group_panel.'.$side) }}
                                        <span class="cl-group-count" x-text="count(@js($side))">0</span>
                                    </h3>
                                </div>
                                <div class="cl-group-search">
                                    <x-filament::input.wrapper>
                                        <x-filament::input
                                            type="search"
                                            x-model="search.{{ $side }}"
                                            x-on:keydown.enter.prevent
                                            :placeholder="__('admin.checkout_link.group_panel.search')"
                                            :aria-label="__('admin.checkout_link.group_panel.search_'.$side)"
                                        />
                                    </x-filament::input.wrapper>
                                </div>
                                <div class="cl-group-list" role="list">
                                    @foreach ($groups as $group)
                                        @php $groupId = (int) $group->id; @endphp
                                        @continue($side === 'available' && $group->trashed())
                                        <div role="listitem" wire:key="checkout-link-group-{{ $side }}-{{ $merchantId }}-{{ $groupId }}" x-show="visible(@js($side), items.find(item => item.id === {{ $groupId }}))" x-cloak>
                                            @if ($side === 'available')
                                                <button type="button" class="cl-group-row cl-group-row-available" x-on:click="add({{ $groupId }})" aria-label="{{ __('admin.checkout_link.group_panel.include_named', ['name' => $group->group_name]) }}">
                                                    <span class="cl-group-name">
                                                        <span class="cl-group-name-line">{{ $group->group_name }}</span>
                                                        <span class="cl-group-meta"><span class="cl-group-code">{{ $group->group_key }}</span></span>
                                                    </span>
                                                    <span class="cl-group-add" aria-hidden="true">+</span>
                                                </button>
                                            @else
                                                <div class="cl-group-row">
                                                    <span class="cl-group-name">
                                                        <span class="cl-group-name-line">{{ $group->group_name }}</span>
                                                        <span class="cl-group-meta">
                                                            <span class="cl-group-code">{{ $group->group_key }}</span>
                                                            @if ($group->trashed())
                                                                <span>{{ __('admin.checkout_link.group_panel.deleted') }}</span>
                                                            @elseif (! $group->is_active)
                                                                <span>{{ __('admin.checkout_link.group_panel.inactive') }}</span>
                                                            @endif
                                                        </span>
                                                    </span>
                                                    <span style="display: inline-flex; gap: 0.5rem;">
                                                        @unless ($group->trashed())
                                                            <button type="button" class="cl-group-default" x-on:click="makeDefault({{ $groupId }})" x-bind:aria-pressed="isDefault({{ $groupId }}) ? 'true' : 'false'">
                                                                {{ __('admin.checkout_link.group_panel.default') }}
                                                            </button>
                                                        @endunless
                                                        <button type="button" class="cl-group-remove" x-on:click="remove({{ $groupId }})" aria-label="{{ __('admin.checkout_link.group_panel.remove_named', ['name' => $group->group_name]) }}">×</button>
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                    <p class="cl-group-empty" x-show="count(@js($side)) === 0" x-cloak>{{ __('admin.checkout_link.group_panel.empty_'.$side) }}</p>
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="cl-group-empty">{{ __('admin.checkout_link.group_panel.choose_merchant') }}</p>
            @endif
        </x-filament::section>
    </div>
</x-dynamic-component>

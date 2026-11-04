@once
    <style>
        .pg-method-panel { min-width: 0; border: 0; padding: 0; margin: 0; container-type: inline-size; }
        .pg-method-panel:disabled { opacity: 0.65; }
        .pg-method-columns { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        @container (min-width: 32rem) {
            .pg-method-columns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .pg-method-column { min-width: 0; overflow: hidden; border: 1px solid color-mix(in srgb, currentColor 13%, transparent); border-radius: 0.75rem; }
        .pg-method-column-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.875rem 1rem; background: color-mix(in srgb, currentColor 3%, transparent); }
        .pg-method-column-title { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 600; }
        .pg-method-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.5rem; padding: 0.1rem 0.4rem; border-radius: 0.375rem; background: color-mix(in srgb, currentColor 7%, transparent); font-size: 0.75rem; font-variant-numeric: tabular-nums; }
        .pg-method-search { padding: 0.75rem 0.875rem; }
        .pg-method-caption { display: grid; grid-template-columns: minmax(0, 1fr) 5.5rem 2rem; align-items: center; gap: 0.75rem; padding: 0 1rem 0.5rem; font-size: 0.6875rem; opacity: 0.6; }
        .pg-method-caption-weight { text-align: center; }
        .pg-method-list { min-height: 15rem; max-height: 28rem; overflow-y: auto; padding: 0 0.5rem 0.5rem; }
        .pg-method-row { display: grid; grid-template-columns: minmax(0, 1fr) 5.5rem 2rem; align-items: center; gap: 0.75rem; width: 100%; min-height: 3.75rem; padding: 0.625rem 0.5rem; border-radius: 0.5rem; text-align: start; }
        .pg-method-list > [role="listitem"] + [role="listitem"] { border-top: 1px solid color-mix(in srgb, currentColor 7%, transparent); }
        .pg-method-row-available { grid-template-columns: minmax(0, 1fr) 2rem; cursor: pointer; }
        .pg-method-row:hover { background: color-mix(in srgb, currentColor 3%, transparent); }
        .pg-method-row-available:focus-visible, .pg-method-remove:focus-visible { outline: 2px solid var(--primary-500, currentColor); outline-offset: -2px; }
        .pg-method-name { min-width: 0; }
        .pg-method-name-line { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.875rem; font-weight: 500; }
        .pg-method-meta { display: flex; align-items: center; gap: 0.5rem; min-width: 0; padding-top: 0.2rem; font-size: 0.6875rem; opacity: 0.6; }
        .pg-method-code { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .pg-method-status { flex-shrink: 0; max-width: 9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pg-method-add, .pg-method-remove { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border: 1px solid color-mix(in srgb, currentColor 12%, transparent); border-radius: 0.5rem; background: transparent; font-size: 1.125rem; line-height: 1; }
        .pg-method-add { color: var(--primary-600, currentColor); }
        .pg-method-remove { cursor: pointer; opacity: 0.65; }
        .pg-method-remove:hover { color: var(--danger-600, currentColor); opacity: 1; background: color-mix(in srgb, currentColor 5%, transparent); }
        .pg-method-weight { width: 5.5rem; }
        .pg-method-weight input { text-align: center; font-variant-numeric: tabular-nums; }
        .pg-method-error { grid-column: 1 / -1; font-size: 0.75rem; color: var(--danger-600, #dc2626); }
        .pg-method-empty { padding: 2.25rem 1rem; text-align: center; font-size: 0.8125rem; opacity: 0.6; }
        .pg-method-hint { display: flex; align-items: baseline; flex-wrap: wrap; justify-content: space-between; gap: 0.5rem 1.5rem; padding-top: 0.875rem; font-size: 0.75rem; opacity: 0.6; }
        .pg-method-hint span:first-child { flex: 1 1 20rem; }
        @media (max-width: 420px) {
            .pg-method-row, .pg-method-caption { grid-template-columns: minmax(0, 1fr) 4.5rem 1.75rem; gap: 0.5rem; }
            .pg-method-row-available { grid-template-columns: minmax(0, 1fr) 1.75rem; }
            .pg-method-weight { width: 4.5rem; }
            .pg-method-add, .pg-method-remove { width: 1.75rem; height: 1.75rem; }
        }
    </style>
@endonce

@php
    $panelItems = collect($columns)->map(fn ($items) => $items->map(fn ($method) => [
        'id' => (int) $method->id,
        'search' => mb_strtolower($method->method_name . ' ' . $method->method_code),
    ])->values()->all())->all();
    $draftSelection = collect(is_array($selectionState) ? $selectionState : []);
@endphp

<fieldset
    class="pg-method-panel"
    wire:key="group-method-lists-{{ $draft ? 'draft-' . ($merchantId ?? 'none') : sha1(serialize($panelItems)) }}"
    x-cloak
    wire:loading.attr="disabled"
    wire:target="{{ $draft ? 'data.merchant_id' : 'setPaymentMethodMembership,savePaymentMethodWeight' }}"
    x-data="{
        draft: @js($draft),
        items: @js($panelItems),
        selection: @if ($draft) $wire.$entangle(@js($statePath)) @else [] @endif,
        @if ($draft) search: { available: '', included: '' }, @endif
        selected(id) { return Array.isArray(this.selection) && this.selection.some(item => item && Number(item.id) === Number(id)) },
        visible(side, id, text, filtering = true) {
            const included = side === 'included';
            return (!this.draft || this.selected(id) === included)
                && (!filtering || text.includes(this.search[side].trim().toLowerCase()));
        },
        count(side, filtering = false) { return this.items[side].filter(item => this.visible(side, item.id, item.search, filtering)).length },
        add(id) { if (!this.selected(id)) this.selection = [...(Array.isArray(this.selection) ? this.selection : []), { id, priority: 100 }] },
        remove(id) { this.selection = (Array.isArray(this.selection) ? this.selection : []).filter(item => item && Number(item.id) !== Number(id)) },
        weightFor(id) { return (Array.isArray(this.selection) ? this.selection : []).find(item => item && Number(item.id) === Number(id))?.priority ?? 100 },
        setWeight(id, value) { this.selection = (Array.isArray(this.selection) ? this.selection : []).map(item => item && Number(item.id) === Number(id) ? { ...item, priority: value } : item) },
    }"
>
    <div class="pg-method-columns">
        @foreach ($columns as $side => $methods)
            @php $available = $side === 'available'; @endphp
            <section class="pg-method-column" aria-label="{{ __('admin.payment_group.method_panel.' . $side) }}">
                <div class="pg-method-column-head">
                    <h3 class="pg-method-column-title">
                        {{ __('admin.payment_group.method_panel.' . $side) }}
                        <span class="pg-method-count" x-text="count(@js($side))">{{ $draft ? 0 : $methods->count() }}</span>
                    </h3>
                </div>
                <div class="pg-method-search">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="search"
                            x-model="search.{{ $side }}"
                            x-on:keydown.enter.prevent
                            :placeholder="__('admin.payment_group.method_panel.search')"
                            :aria-label="__('admin.payment_group.method_panel.search_' . $side)"
                        />
                    </x-filament::input.wrapper>
                </div>
                <div class="pg-method-caption" aria-hidden="true">
                    <span>{{ __('admin.payment_group.method_panel.method_column') }}</span>
                    <span class="pg-method-caption-weight">{{ $available ? '' : __('admin.payment_group.method_panel.weight_column') }}</span>
                    <span></span>
                </div>
                <div class="pg-method-list" role="list">
                    @foreach ($methods as $method)
                        @php
                            $methodId = (int) $method->id;
                            $selectionIndex = $draftSelection->search(fn ($item) => is_array($item) && (int) ($item['id'] ?? 0) === $methodId);
                            $errorKey = $draft ? $statePath . '.' . $selectionIndex . '.priority' : 'weights.' . $methodId;
                        @endphp

                        @if ($available)
                            <div
                                role="listitem"
                                wire:key="group-method-available-{{ $merchantId ?? 'edit' }}-{{ $methodId }}"
                                x-show="visible('available', {{ $methodId }}, @js(mb_strtolower($method->method_name . ' ' . $method->method_code)))"
                                x-cloak
                            >
                                <button
                                    type="button"
                                    class="pg-method-row pg-method-row-available"
                                    @if ($draft)
                                        x-on:click="add({{ $methodId }})"
                                    @else
                                        wire:click="setPaymentMethodMembership({{ $methodId }}, true)"
                                    @endif
                                    aria-label="{{ __('admin.payment_group.method_panel.include_named', ['name' => $method->method_name]) }}"
                                >
                                    <span class="pg-method-name">
                                        <span class="pg-method-name-line" title="{{ $method->method_name }}">{{ $method->method_name }}</span>
                                        <span class="pg-method-meta">
                                            <span class="pg-method-code">{{ $method->method_code }}</span>
                                            @if (! $method->is_active)
                                                <span class="pg-method-status">{{ __('admin.payment_group.method_panel.inactive') }}</span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="pg-method-add" aria-hidden="true">+</span>
                                </button>
                            </div>
                        @else
                            <div
                                role="listitem"
                                class="pg-method-row"
                                data-weight-error="{{ $errors->has($errorKey) ? 'true' : 'false' }}"
                                wire:key="group-method-included-{{ $merchantId ?? 'edit' }}-{{ $methodId }}-{{ $method->group_weight }}"
                                x-show="visible('included', {{ $methodId }}, @js(mb_strtolower($method->method_name . ' ' . $method->method_code)))"
                                x-cloak
                                @if (! $draft)
                                    x-data="{ weight: @js($method->group_weight), initialWeight: @js($method->group_weight), saveWeight() { if (String(this.weight) !== String(this.initialWeight) || this.$root.dataset.weightError === 'true') $wire.savePaymentMethodWeight({{ $methodId }}, this.weight) } }"
                                @endif
                            >
                                <div class="pg-method-name">
                                    <span class="pg-method-name-line" title="{{ $method->method_name }}">{{ $method->method_name }}</span>
                                    <span class="pg-method-meta">
                                        <span class="pg-method-code">{{ $method->method_code }}</span>
                                        @if ($method->trashed())
                                            <span class="pg-method-status">{{ __('admin.payment_group.method_panel.deleted') }}</span>
                                        @elseif (! $method->is_active)
                                            <span class="pg-method-status">{{ __('admin.payment_group.method_panel.inactive') }}</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="pg-method-weight">
                                    <x-filament::input.wrapper :valid="! $errors->has($errorKey)">
                                        <x-filament::input
                                            type="number"
                                            min="1"
                                            max="2147483647"
                                            step="1"
                                            :disabled="$method->trashed()"
                                            :aria-label="__('admin.payment_group.method_panel.save_weight_named', ['name' => $method->method_name])"
                                            :aria-invalid="$errors->has($errorKey) ? 'true' : null"
                                            :aria-describedby="$errors->has($errorKey) ? 'group-method-error-' . ($draft ? 'draft-' : 'edit-') . $methodId : null"
                                            :x-model="$draft ? null : 'weight'"
                                            :x-bind:value="$draft ? 'weightFor(' . $methodId . ')' : null"
                                            :x-on:input="$draft ? 'setWeight(' . $methodId . ', $event.target.value)' : null"
                                            :x-on:blur="$draft ? null : 'saveWeight()'"
                                            :x-on:keydown.enter.prevent="$draft ? '' : 'saveWeight()'"
                                        />
                                    </x-filament::input.wrapper>
                                </div>
                                <button
                                    type="button"
                                    class="pg-method-remove"
                                    @if ($draft)
                                        x-on:click="remove({{ $methodId }})"
                                    @else
                                        wire:click="setPaymentMethodMembership({{ $methodId }}, false)"
                                    @endif
                                    aria-label="{{ __('admin.payment_group.method_panel.remove_named', ['name' => $method->method_name]) }}"
                                    title="{{ __('admin.payment_group.method_panel.remove_named', ['name' => $method->method_name]) }}"
                                ><span aria-hidden="true">−</span></button>
                                @error($errorKey)
                                    <p id="group-method-error-{{ $draft ? 'draft-' : 'edit-' }}{{ $methodId }}" role="alert" class="pg-method-error">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    @endforeach
                    <p class="pg-method-empty" x-show="count(@js($side), true) === 0" x-cloak>
                        <span x-show="!search.{{ $side }}.trim()">{{ __('admin.payment_group.method_panel.empty_' . $side) }}</span>
                        <span x-show="search.{{ $side }}.trim()">{{ __('admin.payment_group.method_panel.no_results') }}</span>
                    </p>
                </div>
            </section>
        @endforeach
    </div>
    <p class="pg-method-hint">
        <span>{{ __('admin.payment_group.relation_manager.priority_help') }}</span>
        <span>{{ __('admin.payment_group.method_panel.' . ($draft ? 'draft_hint' : 'autosave_hint')) }}</span>
    </p>
</fieldset>

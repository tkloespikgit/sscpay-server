@extends('checkout.layout')

@section('title', __('checkout.page_title', ['title' => $link->title]))

@section('content')
    @if (session('checkout_error'))
        <div class="checkout-alert mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {{ session('checkout_error') }}
        </div>
    @endif

<div @class(['checkout-shell', 'checkout-shell--no-summary' => ! $link->isFixedAmount()])>

    {{-- 区间金额由客户自行输入，此时不展示订单摘要。 --}}
    @if ($link->isFixedAmount())
    <aside class="checkout-summary rounded-xl border border-slate-200 bg-slate-50 p-6">
        <h2 class="mb-5 text-base font-semibold tracking-tight text-slate-900">
            {{ __('checkout.sections.order_summary') }}
        </h2>

        @if ($link->items->isNotEmpty())
            <ul class="mb-4 divide-y divide-slate-100">
                @foreach ($link->items as $item)
                    <li class="flex items-center gap-4 py-3">
                        @if ($item->imageUrl())
                            <img src="{{ $item->imageUrl() }}" alt=""
                                 class="h-14 w-14 shrink-0 rounded-lg border border-slate-200 object-cover">
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $item->product_name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ __('checkout.summary.quantity', ['count' => $item->quantity]) }}
                            </p>
                        </div>
                        <p class="shrink-0 text-sm font-medium tabular-nums">
                            {{ $link->currency }} {{ number_format((float) $item->totalPrice(), 2) }}
                        </p>
                    </li>
                @endforeach
            </ul>

            <dl class="space-y-1.5 border-t border-slate-100 pt-4 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-600">{{ __('checkout.summary.subtotal') }}</dt>
                    <dd class="tabular-nums">{{ $link->currency }} {{ number_format((float) $link->itemsSubtotal(), 2) }}</dd>
                </div>
                @if (bccomp((string) $link->shipping_fee, '0', 2) !== 0)
                    <div class="flex justify-between">
                        <dt class="text-slate-600">{{ __('checkout.summary.shipping') }}</dt>
                        <dd class="tabular-nums">{{ $link->currency }} {{ number_format((float) $link->shipping_fee, 2) }}</dd>
                    </div>
                @endif
                @if (bccomp((string) $link->tax, '0', 2) !== 0)
                    <div class="flex justify-between">
                        <dt class="text-slate-600">{{ __('checkout.summary.tax') }}</dt>
                        <dd class="tabular-nums">{{ $link->currency }} {{ number_format((float) $link->tax, 2) }}</dd>
                    </div>
                @endif
                @if (bccomp((string) $link->discount, '0', 2) !== 0)
                    <div class="flex justify-between text-emerald-700">
                        <dt>{{ __('checkout.summary.discount') }}</dt>
                        <dd class="tabular-nums">−{{ $link->currency }} {{ number_format((float) $link->discount, 2) }}</dd>
                    </div>
                @endif
            </dl>
        @endif

        <div class="mt-4 flex items-baseline justify-between border-t border-slate-200 pt-4">
            <span class="text-sm font-semibold">{{ __('checkout.summary.total') }}</span>
            <span class="text-2xl font-semibold tabular-nums">
                {{ $link->currency }} {{ number_format((float) $link->fixed_amount, 2) }}
            </span>
        </div>
    </aside>
    @endif

    <form method="POST" action="{{ route('checkout.store', ['slug' => $link->slug]) }}" id="checkout-form"
          class="checkout-form space-y-10" novalidate>
        @csrf
        <input type="hidden" name="form_token" value="{{ $formToken }}">

        {{-- 蜜罐。真人看不到也 tab 不到（tabindex="-1" + aria-hidden），
             脚本会把所有 input 填满，填了就直接判失败。
             用 CSS 隐藏而不是 type="hidden"：后者稍微聪明点的脚本会跳过。 --}}
        <div class="absolute left-[-9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
            <label>Company website<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label>
        </div>

        @if (filled($link->customer_notice))
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-5 py-4" role="note">
                <p class="whitespace-pre-line text-sm leading-6 text-slate-700">{{ $link->customer_notice }}</p>
            </div>
        @endif

        {{-- 金额区间模式：客户自己输金额。固定金额模式下这一段整个不渲染，
             服务端也会忽略任何传上来的 amount（见 CheckoutLink::resolveAmount）。 --}}
        @unless ($link->isFixedAmount())
            <section>
                <h2 class="mb-5 text-xl font-semibold tracking-tight text-slate-900">
                    {{ __('checkout.fields.amount') }}
                </h2>
                <div>
                    <label for="amount" class="block text-sm font-medium">{{ __('checkout.fields.amount') }}</label>
                    <div class="mt-1.5 flex items-center gap-2">
                        <span class="text-sm font-medium text-slate-500">{{ $link->currency }}</span>
                        <input type="text" inputmode="decimal" id="amount" name="amount"
                               value="{{ old('amount') }}"
                               placeholder="{{ __('checkout.placeholders.amount') }}"
                               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">
                        {{ __('checkout.help.amount_range', [
                            'min' => $link->currency.' '.number_format((float) $link->min_amount, 2),
                            'max' => $link->currency.' '.number_format((float) $link->max_amount, 2),
                        ]) }}
                    </p>
                    @error('amount')
                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </section>
        @endunless

        <section>
            <h2 class="mb-5 text-xl font-semibold tracking-tight text-slate-900">
                {{ __('checkout.sections.contact') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @include('checkout.partials.field', ['name' => 'first_name', 'autocomplete' => 'given-name'])
                @include('checkout.partials.field', ['name' => 'last_name', 'autocomplete' => 'family-name'])
                @include('checkout.partials.field', ['name' => 'email', 'type' => 'email', 'autocomplete' => 'email', 'span' => true])
                <div class="sm:col-span-2">
                    <label for="phone" class="block text-sm font-medium">{{ __('checkout.fields.phone') }}</label>
                    <input type="tel" id="phone" name="phone" autocomplete="tel" required
                           value="{{ old('phone') }}" placeholder="{{ __('checkout.placeholders.phone') }}"
                           data-recommended-country="{{ $recommendedCountry }}"
                           data-allowed-countries='@json(array_keys($countries))'
                           @class([
                               'mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1',
                               'border-slate-300 focus:border-slate-900 focus:ring-slate-900' => ! $errors->has('phone'),
                               'border-red-400 focus:border-red-500 focus:ring-red-500' => $errors->has('phone'),
                           ])>
                    @error('phone')
                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                    @else
                        <p class="mt-1.5 text-xs text-slate-500">{{ __('checkout.help.phone') }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <section>
            <h2 class="mb-5 text-xl font-semibold tracking-tight text-slate-900">
                {{ __('checkout.sections.shipping') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @include('checkout.partials.field', [
                    'name' => 'address_line1',
                    'autocomplete' => 'street-address',
                    'span' => true,
                    'placeholder' => __('checkout.placeholders.address_line1'),
                    'help' => $googleMapsKey ? __('checkout.help.address_autocomplete') : null,
                ])
                @include('checkout.partials.field', ['name' => 'address_line2', 'required' => false, 'span' => true])
                @if ($googleMapsKey)
                    @include('checkout.partials.field', ['name' => 'city', 'autocomplete' => 'address-level2'])
                    @include('checkout.partials.field', ['name' => 'state', 'required' => false, 'autocomplete' => 'address-level1'])
                @endif

                <div id="country-picker" class="relative">
                    <label for="country-search" class="block text-sm font-medium">{{ __('checkout.fields.country') }}</label>
                    <div class="country-input-wrap">
                        <input id="country-search" type="search" autocomplete="off" hidden
                               placeholder="{{ __('checkout.placeholders.select_country') }}"
                               role="combobox" aria-autocomplete="list" aria-expanded="false"
                               aria-controls="country-options"
                               class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1">
                        <svg class="country-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" hidden><path d="m5 7.5 5 5 5-5" /></svg>
                    </div>
                    <select id="country" name="country" autocomplete="country"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                        <option value="">—</option>
                        @foreach ($countries as $code => $name)
                            <option value="{{ $code }}" @selected(old('country', $recommendedCountry) === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <div id="country-options" role="listbox" hidden
                         data-empty-label="{{ __('checkout.placeholders.no_countries') }}"
                         class="country-options"></div>
                    @error('country')
                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                @unless ($googleMapsKey)
                    <div id="state-picker" class="relative">
                        <label for="state-select" class="block text-sm font-medium">{{ __('checkout.fields.state') }}</label>
                        <div class="country-input-wrap">
                            <input id="state-search" type="search" autocomplete="off" hidden
                                   placeholder="{{ __('checkout.placeholders.select_country_first') }}"
                                   role="combobox" aria-autocomplete="list" aria-expanded="false"
                                   aria-controls="state-options"
                                   class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1">
                            <svg class="country-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" hidden><path d="m5 7.5 5 5 5-5" /></svg>
                        </div>
                        <select id="state-select" name="state" autocomplete="address-level1"
                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm">
                            <option value="">{{ __('checkout.placeholders.select_country_first') }}</option>
                        </select>
                        <div id="state-options" role="listbox" hidden
                             data-empty-label="{{ __('checkout.placeholders.no_states') }}"
                             class="country-options"></div>
                        <input id="state-manual" name="state" type="text" maxlength="100" autocomplete="address-level1"
                               value="{{ old('state') }}" hidden disabled
                               class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm"
                               placeholder="{{ __('checkout.placeholders.enter_state') }}">
                        @error('state') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div id="city-picker" class="relative">
                        <label for="city-select" class="block text-sm font-medium">{{ __('checkout.fields.city') }}</label>
                        <div class="country-input-wrap">
                            <input id="city-search" type="search" autocomplete="off" hidden
                                   placeholder="{{ __('checkout.placeholders.select_state_first') }}"
                                   role="combobox" aria-autocomplete="list" aria-expanded="false"
                                   aria-controls="city-options"
                                   class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1">
                            <svg class="country-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" hidden><path d="m5 7.5 5 5 5-5" /></svg>
                        </div>
                        <select id="city-select" name="city" autocomplete="address-level2" disabled required
                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm">
                            <option value="">{{ __('checkout.placeholders.select_state_first') }}</option>
                        </select>
                        <div id="city-options" role="listbox" hidden
                             data-empty-label="{{ __('checkout.placeholders.no_cities') }}"
                             class="country-options"></div>
                        <input id="city-manual" name="city" type="text" maxlength="100" autocomplete="address-level2"
                               value="{{ old('city') }}" hidden disabled required
                               class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm"
                               placeholder="{{ __('checkout.placeholders.enter_city') }}">
                        @error('city') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endunless

                @include('checkout.partials.field', ['name' => 'zip', 'required' => false, 'autocomplete' => 'postal-code'])
            </div>
        </section>

        <section class="space-y-4">
            @if ($turnstileSiteKey)
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="light"></div>
                @error('cf-turnstile-response')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
            @endif

            @error('form_token')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror

            <button type="submit" id="checkout-submit"
                    data-submitting-label="{{ __('checkout.actions.submitting') }}"
                    class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
                {{ __('checkout.actions.pay') }}
            </button>
        </section>
    </form>
</div>
@endsection

@push('scripts')
    @vite('resources/js/checkout.js')
    @if ($turnstileSiteKey)
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    <script>
        document.getElementById('checkout-form').addEventListener('submit', function () {
            const button = document.getElementById('checkout-submit');
            button.disabled = true;
            button.textContent = button.dataset.submittingLabel;
        });
    </script>

    @if ($googleMapsKey)
        <script>
            // Google Places 自动填充。选中一个地址后把各字段拆开回填。
            // 注意 country 取的是 short_name（ISO alpha-2），而不是 long_name
            // ——orders.shipping_country 是 char(2)，填 "Germany" 会被截断成 "Ge"。
            function initCheckoutAutocomplete() {
                const input = document.getElementById('address_line1');
                if (!input || !window.google?.maps?.places) return;

                const autocomplete = new google.maps.places.Autocomplete(input, {
                    fields: ['address_components'],
                    types: ['address'],
                });

                autocomplete.addListener('place_changed', function () {
                    const place = autocomplete.getPlace();
                    if (!place.address_components) return;

                    const get = (type, form = 'long_name') => {
                        const part = place.address_components.find((c) => c.types.includes(type));
                        return part ? part[form] : '';
                    };

                    const streetNumber = get('street_number');
                    const route = get('route');
                    // 门牌号在前还是路名在前因国家而异，这里统一用 "路名 门牌号"
                    // 之外的顺序都交给客户自己微调——强行按国家规则拼反而更容易出错。
                    input.value = [route, streetNumber].filter(Boolean).join(' ').trim() || input.value;

                    const setValue = (id, value) => {
                        const el = document.getElementById(id);
                        if (el && value) el.value = value;
                    };

                    setValue('city', get('locality') || get('postal_town') || get('sublocality'));
                    setValue('state', get('administrative_area_level_1'));
                    setValue('zip', get('postal_code'));
                    const placeCountry = get('country', 'short_name');
                    const countrySelect = document.getElementById('country');
                    if (placeCountry) {
                        countrySelect.value = countrySelect.querySelector(`option[value="${placeCountry}"]`)
                            ? placeCountry : '';
                        countrySelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });

                // 在地址框里按回车会触发表单提交，而此时客户往往只是想选中
                // 下拉里的建议项——拦掉回车的默认行为。
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') event.preventDefault();
                });
            }
        </script>
        <script async defer
                src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsKey }}&libraries=places&callback=initCheckoutAutocomplete"></script>
    @else
        <script>
            (() => {
                const country = document.getElementById('country');
                const stateSelect = document.getElementById('state-select');
                const stateManual = document.getElementById('state-manual');
                const citySelect = document.getElementById('city-select');
                const cityManual = document.getElementById('city-manual');
                const oldState = @json(old('state'));
                const oldCity = @json(old('city'));
                const manualValue = '__manual__';
                let requestId = 0;
                const refreshPicker = (select) => select.dispatchEvent(new Event('checkout-options-updated'));

                const options = (select, values, placeholder) => {
                    select.replaceChildren(new Option(placeholder, ''));
                    values.forEach(value => select.add(new Option(value, value)));
                    select.add(new Option(@json(__('checkout.placeholders.enter_manually')), manualValue));
                    select.disabled = false;
                    refreshPicker(select);
                };
                const manual = (select, input, enabled) => {
                    select.hidden = enabled;
                    if (enabled) select.disabled = true;
                    input.hidden = !enabled;
                    input.disabled = !enabled;
                    select.dispatchEvent(new CustomEvent('checkout-picker-mode', { detail: { manual: enabled } }));
                };
                const fetchItems = async (url) => {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('Location lookup failed');
                    return response.json();
                };

                async function loadCities(selected = '') {
                    const current = ++requestId;
                    citySelect.replaceChildren(new Option(@json(__('checkout.placeholders.select_state_first')), ''));
                    citySelect.disabled = true;
                    refreshPicker(citySelect);
                    cityManual.value = selected;
                    manual(citySelect, cityManual, false);
                    if (!stateSelect.value || stateSelect.value === manualValue) {
                        manual(citySelect, cityManual, true);
                        return;
                    }
                    try {
                        const url = new URL(@json(route('checkout.locations.cities', absolute: false)), window.location.href);
                        url.searchParams.set('country', country.value);
                        url.searchParams.set('state', stateSelect.value);
                        const { cities } = await fetchItems(url);
                        if (current !== requestId) return;
                        if (!cities.length) throw new Error('No cities');
                        options(citySelect, cities, @json(__('checkout.placeholders.select_city')));
                        citySelect.value = cities.includes(selected) ? selected : '';
                        refreshPicker(citySelect);
                        if (selected && !cities.includes(selected)) {
                            citySelect.value = manualValue;
                            manual(citySelect, cityManual, true);
                        }
                    } catch (_) {
                        if (current === requestId) manual(citySelect, cityManual, true);
                    }
                }

                async function loadStates(selected = '', selectedCity = '') {
                    ++requestId;
                    stateSelect.replaceChildren(new Option(@json(__('checkout.placeholders.select_country_first')), ''));
                    stateSelect.disabled = true;
                    refreshPicker(stateSelect);
                    stateManual.value = selected;
                    manual(stateSelect, stateManual, false);
                    citySelect.replaceChildren(new Option(@json(__('checkout.placeholders.select_state_first')), ''));
                    citySelect.disabled = true;
                    refreshPicker(citySelect);
                    manual(citySelect, cityManual, false);
                    if (!country.value) return;
                    const current = requestId;
                    try {
                        const url = new URL(@json(route('checkout.locations.states', absolute: false)), window.location.href);
                        url.searchParams.set('country', country.value);
                        const { states } = await fetchItems(url);
                        if (current !== requestId) return;
                        if (!states.length) throw new Error('No states');
                        options(stateSelect, states, @json(__('checkout.placeholders.select_state')));
                        stateSelect.value = states.includes(selected) ? selected : '';
                        refreshPicker(stateSelect);
                        if (selected && !states.includes(selected)) {
                            stateSelect.value = manualValue;
                            manual(stateSelect, stateManual, true);
                        }
                        await loadCities(selectedCity);
                    } catch (_) {
                        if (current === requestId) {
                            manual(stateSelect, stateManual, true);
                            cityManual.value = selectedCity;
                            manual(citySelect, cityManual, true);
                        }
                    }
                }

                country.addEventListener('change', () => {
                    stateManual.value = '';
                    cityManual.value = '';
                    loadStates();
                });
                stateSelect.addEventListener('change', () => {
                    stateManual.value = '';
                    cityManual.value = '';
                    manual(stateSelect, stateManual, stateSelect.value === manualValue);
                    loadCities();
                });
                citySelect.addEventListener('change', () => {
                    cityManual.value = '';
                    manual(citySelect, cityManual, citySelect.value === manualValue);
                });
                loadStates(oldState || '', oldCity || '');
            })();
        </script>
    @endif
@endpush

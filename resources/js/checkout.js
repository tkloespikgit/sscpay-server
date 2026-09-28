import intlTelInput from 'intl-tel-input';
import 'intl-tel-input/styles';

const phone = document.getElementById('phone');
const form = document.getElementById('checkout-form');

function enhanceLocationSelect(name) {
    const picker = document.getElementById(`${name}-picker`);
    const select = document.getElementById(name === 'country' ? 'country' : `${name}-select`);
    const input = document.getElementById(`${name}-search`);
    const list = document.getElementById(`${name}-options`);
    const manualInput = document.getElementById(`${name}-manual`);
    if (!picker || !select || !input || !list) return;

    const label = picker.querySelector('label');
    const chevron = picker.querySelector('.country-chevron');
    const defaultPlaceholder = input.placeholder;
    let visibleOptions = [];
    let activeIndex = -1;

    const options = () => Array.from(select.options)
        .filter(option => option.value)
        .map(option => ({ value: option.value, label: option.textContent }));
    const selectedLabel = () => options().find(option => option.value === select.value)?.label || '';

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        input.value = selectedLabel();
        activeIndex = -1;
    };

    const markActive = (index) => {
        activeIndex = index;
        const buttons = list.querySelectorAll('[role="option"]');
        buttons.forEach((button, optionIndex) => {
            button.classList.toggle('is-active', optionIndex === index);
        });
        const active = buttons[index];
        if (active) {
            input.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        }
    };

    const choose = (value) => {
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    };

    const render = (query = '') => {
        if (select.disabled || (manualInput && !manualInput.hidden)) return;
        const needle = query.trim().toLocaleLowerCase();
        const matches = options().filter(option =>
            option.label.toLocaleLowerCase().includes(needle) || option.value.toLowerCase().includes(needle));
        const manualOption = matches.find(option => option.value === '__manual__');
        visibleOptions = matches.filter(option => option.value !== '__manual__')
            .slice(0, name === 'city' ? 200 : undefined);
        const selected = matches.find(option => option.value === select.value);
        if (selected && !visibleOptions.some(option => option.value === selected.value) && selected.value !== '__manual__') {
            visibleOptions.unshift(selected);
        }
        if (manualOption) visibleOptions.push(manualOption);
        list.replaceChildren();

        if (!visibleOptions.length) {
            const empty = document.createElement('p');
            empty.className = 'country-options-empty';
            empty.textContent = list.dataset.emptyLabel;
            list.append(empty);
        }

        visibleOptions.forEach((option, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.id = `${name}-option-${index}`;
            button.setAttribute('role', 'option');
            button.tabIndex = -1;
            button.className = 'country-option';
            button.setAttribute('aria-selected', String(option.value === select.value));
            button.textContent = option.label;
            button.addEventListener('click', () => choose(option.value));
            list.append(button);
        });

        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        markActive(visibleOptions.findIndex(option => option.value === select.value));
    };

    const sync = () => {
        const manualMode = manualInput && !manualInput.hidden && !manualInput.disabled;
        select.hidden = true;
        input.hidden = Boolean(manualMode);
        chevron.hidden = Boolean(manualMode);
        label.htmlFor = manualMode ? manualInput.id : input.id;
        input.disabled = select.disabled || Boolean(manualMode);
        input.placeholder = select.disabled
            ? select.options[0]?.textContent || defaultPlaceholder
            : defaultPlaceholder;
        close();
    };

    select.addEventListener('change', sync);
    select.addEventListener('checkout-options-updated', sync);
    select.addEventListener('checkout-picker-mode', sync);

    input.addEventListener('focus', () => {
        input.select();
        render();
    });
    input.addEventListener('input', () => render(input.value));
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            input.blur();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (list.hidden) render();
            if (visibleOptions.length) {
                markActive((activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + visibleOptions.length) % visibleOptions.length);
            }
        } else if (event.key === 'Enter' && !list.hidden) {
            event.preventDefault();
            const choice = visibleOptions[activeIndex] || visibleOptions[0];
            if (choice) choose(choice.value);
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (!picker.contains(event.target)) close();
    });
    picker.addEventListener('focusout', (event) => {
        if (!picker.contains(event.relatedTarget)) close();
    });

    // The native select remains the submitted field and no-JavaScript fallback.
    // Location lookups update its options and send checkout-options-updated.
    sync();
}

enhanceLocationSelect('country');
enhanceLocationSelect('state');
enhanceLocationSelect('city');

if (phone && form) {
    const allowedCountries = JSON.parse(phone.dataset.allowedCountries || '[]').map(code => code.toLowerCase());
    const recommendedCountry = phone.dataset.recommendedCountry?.toLowerCase();
    const iti = intlTelInput(phone, {
        ...(allowedCountries.length ? { onlyCountries: allowedCountries } : {}),
        initialCountry: recommendedCountry || allowedCountries[0] || 'us',
        numberDisplayFormat: 'INTERNATIONAL',
        countrySearch: true,
        placeholderNumberPolicy: 'AGGRESSIVE',
        placeholderNumberType: 'MOBILE',
        loadUtils: () => import('intl-tel-input/utils'),
    });

    form.addEventListener('submit', () => {
        try {
            const normalized = iti.getNumber();
            if (normalized) phone.value = normalized;
        } catch (_) {
            // The utils bundle is still loading. Server validation handles the visible value.
        }
    }, { capture: true });
}

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// 执行页面实际使用的脚本，以 Google 桩验证检索参数和表单值，不调用真实 API。
const blade = readFileSync(new URL('../../resources/views/checkout/show.blade.php', import.meta.url), 'utf8');
const stateRules = readFileSync(new URL('../../resources/data/paypal-address-states.json', import.meta.url), 'utf8');
const script = blade.slice(blade.indexOf('function initCheckoutAutocomplete()'), blade.indexOf('</script>', blade.indexOf('function initCheckoutAutocomplete()')))
    .replace('@json(\\App\\Support\\CheckoutAddress::stateRules())', stateRules);

function setup(countries, selected = countries[0]) {
    const elements = Object.fromEntries(['address_line1', 'city', 'state', 'zip', 'country'].map(id => [id, {
        value: '',
        listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        dispatchEvent(event) { this.listeners[event.type]?.(event); },
    }]));
    elements.country.options = [{ value: '' }, ...countries.map(value => ({ value }))];
    elements.country.value = selected;
    let autocomplete;
    class Autocomplete {
        constructor(input, options) { this.options = options; autocomplete = this; }
        addListener(name, callback) { this[name] = callback; }
        setComponentRestrictions(restrictions) { this.options.componentRestrictions = restrictions; }
        getPlace() { return this.place; }
    }
    const context = vm.createContext({
        document: { getElementById: id => elements[id] },
        window: { google: { maps: { places: { Autocomplete } } } },
        google: { maps: { places: { Autocomplete } } },
        Event: class { constructor(type) { this.type = type; } },
    });
    vm.runInContext(`${script}\ninitCheckoutAutocomplete();`, context);
    return {
        elements,
        autocomplete,
        choose(country, stateName, stateCode, extra = []) {
            autocomplete.place = { address_components: [
                { types: ['country'], long_name: 'Country', short_name: country },
                { types: ['route'], long_name: 'Main Street' },
                { types: ['street_number'], long_name: '123' },
                { types: ['locality'], long_name: 'City' },
                ...(stateName ? [{ types: ['administrative_area_level_1'], long_name: stateName, short_name: stateCode }] : []),
                ...extra,
            ] };
            autocomplete.place_changed();
        },
    };
}

for (const [country, fullName, shortName, expected] of [
    ['JP', 'Tokyo', 'Tokyo', 'TOKYO-TO'],
    ['IN', 'Maharashtra', 'MH', 'Maharashtra'],
    ['IN', 'Delhi', 'DL', 'Delhi (NCT)'],
    ['MX', 'Quintana Roo', 'Q.R.', 'Q ROO'],
    ['CN', 'Beijing', 'BJ', 'CN-BJ'],
    ['TH', 'Bangkok', 'Krung Thep Maha Nakhon', 'Bangkok'],
    ['DE', 'Bavaria', 'BY', 'Bavaria'],
]) {
    test(`${country} 按国家转换 ${fullName} 为 PayPal 值 ${expected}`, () => {
        const { choose, elements } = setup([country]);
        choose(country, fullName, shortName);
        assert.equal(elements.state.value, expected);
    });
}

test('意大利使用 province，不能将 Lazio region 当成 admin_area_1', () => {
    const { choose, elements } = setup(['IT']);
    choose('IT', 'Lazio', 'Lazio', [
        { types: ['administrative_area_level_2'], long_name: 'Metropolitan City of Rome Capital', short_name: 'RM' },
    ]);
    assert.equal(elements.state.value, 'RM');
    assert.equal(elements.city.value, 'City');
});

test('英国使用 county 和 postal_town，不使用 England 或 district', () => {
    const { choose, elements } = setup(['GB']);
    choose('GB', 'England', 'England', [
        { types: ['administrative_area_level_2'], long_name: 'Oxfordshire', short_name: 'Oxon' },
        { types: ['postal_town'], long_name: 'Oxford' },
    ]);
    assert.equal(elements.state.value, 'Oxfordshire');
    assert.equal(elements.city.value, 'Oxford');
});

test('缺少城镇时清空旧城市，不将 sublocality 或 county 当成城市', () => {
    const { autocomplete, elements } = setup(['US']);
    elements.city.value = 'Old City';
    autocomplete.place = { address_components: [
        { types: ['country'], short_name: 'US' },
        { types: ['sublocality'], long_name: 'Neighborhood' },
        { types: ['administrative_area_level_2'], long_name: 'County' },
        { types: ['administrative_area_level_1'], long_name: 'California', short_name: 'CA' },
    ] };
    autocomplete.place_changed();
    assert.equal(elements.city.value, '');
    assert.equal(elements.state.value, 'CA');
});

test('未知省州不能直接采用任意 Google 简称', () => {
    const { choose, elements } = setup(['US']);
    choose('US', 'Unknown State', 'ZZ');
    assert.equal(elements.state.value, '');
});

test('检索限定于当前可选国家，切换国家同步更新（包括超过五个国家）', () => {
    const { elements, autocomplete } = setup(['US', 'CA', 'AU', 'DE', 'FR', 'GB']);
    assert.equal(autocomplete.options.componentRestrictions.country, 'US');
    elements.country.value = 'GB';
    elements.country.dispatchEvent({ type: 'change' });
    assert.equal(autocomplete.options.componentRestrictions.country, 'GB');
});

test('切换国家清空旧省州城市邮编，并同步省州必填状态', () => {
    const { elements } = setup(['US', 'CA', 'DE']);
    assert.equal(elements.state.required, true);
    elements.state.value = 'CA';
    elements.city.value = 'Los Angeles';
    elements.zip.value = '90210';
    elements.country.value = 'CA';
    elements.country.dispatchEvent({ type: 'change' });
    for (const id of ['state', 'city', 'zip']) assert.equal(elements[id].value, '');
    assert.equal(elements.state.required, true);
    elements.country.value = 'DE';
    elements.country.dispatchEvent({ type: 'change' });
    assert.equal(elements.state.required, false);
});

test('未选择国家时仍限定在白名单内，空白名单不初始化 Google', () => {
    assert.equal(setup(['CA', 'US'], '').autocomplete.options.componentRestrictions.country, 'CA');
    assert.equal(setup([]).autocomplete, undefined);
});

for (const [country, fullName, code] of [['US', 'California', 'CA'], ['CA', 'Ontario', 'ON'], ['AU', 'New South Wales', 'NSW']]) {
    test(`${country} 自动填充提交州代码 ${code}，不提交 ${fullName}`, () => {
        const { choose, elements } = setup([country]);
        choose(country, fullName, code);
        assert.equal(elements.state.value, code);
        assert.equal(elements.country.value, country);
        assert.equal(elements.address_line1.value, 'Main Street 123');
    });
}

test('白名单以外、缺失国家及切换国家后的旧结果均不回填', () => {
    const { choose, elements } = setup(['US', 'CA']);
    elements.state.value = 'existing';
    for (const country of ['FR', '', 'CA']) {
        choose(country, 'Ontario', 'ON');
        assert.equal(elements.state.value, 'existing');
        assert.equal(elements.country.value, 'US');
        assert.equal(elements.address_line1.value, '');
    }
});

test('新地址缺少州或邮编时清空旧值；地址框回车不提交表单', () => {
    const { choose, elements } = setup(['US']);
    elements.state.value = 'CA';
    elements.zip.value = '90210';
    choose('US');
    assert.equal(elements.state.value, '');
    assert.equal(elements.zip.value, '');
    let prevented = false;
    elements.address_line1.listeners.keydown({ key: 'Enter', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
});

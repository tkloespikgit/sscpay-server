import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const resource = readFileSync(new URL('../../app/Filament/Resources/OrderResource.php', import.meta.url), 'utf8');
const endFilter = resource.slice(resource.indexOf("DateTimePicker::make('created_to')"));
const hook = endFilter.match(/'x-init'\s*=>\s*<<<'JS'\r?\n([\s\S]*?)\r?\n\s*JS,/);
assert.ok(hook, '结束时间筛选必须提供实际使用的 Alpine 初始化代码。');

// 执行浏览器实际加载的 Filament 包，其中已打包 dayjs，无需额外安装依赖。
const bundle = readFileSync(new URL('../../public/js/filament/forms/components/date-time-picker.js', import.meta.url), 'utf8');
const executable = bundle.replace(/export\s*\{\s*(\w+)\s+as\s+default\s*\};?\s*$/, 'globalThis.makePicker = $1;');
assert.notEqual(executable, bundle, '必须能加载 Filament 的日期时间组件。');

function setup(time, useEndHook = false) {
    const context = vm.createContext({ window: {}, Intl, Date });
    vm.runInContext(executable, context);
    const picker = context.makePicker({
        defaultFocusedDate: `2026-10-16 ${time}`,
        displayFormat: 'YYYY-MM-DD HH:mm:ss',
        firstDayOfWeek: 1,
        isAutofocused: false,
        locale: 'en',
        shouldCloseOnDateSelection: false,
        state: null,
    });
    // 只替代 Alpine 的浏览器调度；日期选择、清空和状态格式化执行真实组件方法。
    picker.$nextTick = callback => callback();
    picker.$watch = () => {};
    picker.$refs = {
        maxDate: { value: '' },
        minDate: { value: '' },
        disabledDates: { value: '[]' },
    };
    picker.$data = picker;
    picker.init();
    if (useEndHook) {
        context.picker = picker;
        vm.runInContext(`with (picker) { ${hook[1]} }`, context);
    }
    return picker;
}

test('开始和结束时间初始化保持为空，不会自动选中日期', () => {
    for (const picker of [setup('00:00:00'), setup('23:59:59', true)]) {
        assert.equal(picker.state, null);
        assert.equal(picker.displayText, '');
    }
});

test('选择开始日期时默认使用 00:00:00', () => {
    const picker = setup('00:00:00');

    picker.selectDate(17);

    assert.equal(picker.state, '2026-10-17 00:00:00');
});

test('选择结束日期时默认使用 23:59:59', () => {
    const picker = setup('23:59:59', true);

    picker.selectDate(17);

    assert.equal(picker.state, '2026-10-17 23:59:59');
});

test('清空结束日期后重新选择仍默认使用 23:59:59', () => {
    const picker = setup('23:59:59', true);
    picker.selectDate(17);

    picker.clearState();
    assert.equal(picker.state, null);
    picker.selectDate(18);

    assert.equal(picker.state, '2026-10-18 23:59:59');
});

test('手动选择时分秒后换日期保留原时间，包括手动选择的午夜', () => {
    for (const [hour, minute, second, expected] of [[12, 34, 56, '12:34:56'], [0, 0, 0, '00:00:00']]) {
        const picker = setup('23:59:59', true);
        picker.selectDate(17);
        picker.hour = hour;
        picker.minute = minute;
        picker.second = second;
        picker.setState(picker.getSelectedDate());

        picker.selectDate(18);

        assert.equal(picker.state, `2026-10-18 ${expected}`);
    }
});

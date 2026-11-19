import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../resources/js/crypto-payment.js', import.meta.url), 'utf8');
const recipient = 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL';
const startedAt = Date.parse('2026-11-19T04:00:00Z');
const ready = {
    state: 'ready', message: 'Ready', request_id: 1, can_requote: false,
    network: 'tron-mainnet', asset: 'USDT', due_amount: '9007199254.740993',
    expires_at: new Date(startedAt + 900000).toISOString(), markup_percent: '2',
    received_amount: '0.000000', confirmed_amount: '0.000000', remaining_amount: '9007199254.740993',
    address: recipient, qr: 'data:image/svg+xml;base64,example', qr_type: 'address', payment_uri: recipient,
};
const amountQr = {
    ...ready, network: 'ethereum-mainnet', qr_type: 'amount', address: `0x${'a'.repeat(40)}`,
    payment_uri: `ethereum:0x${'b'.repeat(40)}@1/transfer?address=0x${'a'.repeat(40)}&uint256=9007199254740993`,
};
const flush = () => new Promise(resolve => setImmediate(resolve));

function withoutPaymentDetails(data, state) {
    return { ...data, state, can_requote: state === 'expired', address: null, qr: null, qr_type: null, payment_uri: null };
}

async function checkout({ initial = ready, statuses = [], storage = new Map(), now = startedAt, token = 'token', clipboardEnabled = true } = {}) {
    const elements = new Map();
    const timers = new Map();
    const intervals = new Map();
    const requests = [];
    const copied = [];
    let timerId = 0;
    const node = () => ({
        hidden: false, disabled: false, textContent: '', value: '', dataset: {}, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        removeAttribute(name) { delete this[name]; },
        focus() { this.focused = true; },
        select() { this.selected = true; },
    });
    for (const id of [
        'payment-quote', 'quote-countdown', 'quote-time', 'quote-amount', 'quote-asset',
        'receipt-details', 'receipt-progress', 'payment-recipient', 'quote-network', 'payment-address',
        'expired-amount', 'expired-address', 'payment-instructions', 'payment-qr', 'payment-qr-hint',
        'payment-status', 'payment-status-line', 'payment-spinner', 'requote', 'open-wallet', 'check-payment',
        'copy-address', 'copy-amount', 'copy-feedback',
    ]) elements.set(id, node());
    const url = '/payment/' + token + '/crypto';
    const labels = {
        statusUrl: url, openUrl: url,
        copied: 'Copied', copyManually: 'Selected', offline: 'Offline', qrAmount: 'Amount QR', qrAddress: 'Address QR',
        preparing: 'Preparing', expired: 'Expired',
    };
    elements.set('crypto-checkout', { dataset: labels });
    class Clock extends Date { static now() { return now; } }
    const document = {
        hidden: false,
        getElementById: id => elements.get(id) ?? null,
        querySelector: () => ({ content: 'csrf' }),
        addEventListener(name, callback) { this[name] = callback; },
    };
    vm.runInNewContext(script, {
        document, Date: Clock, AbortSignal,
        sessionStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) },
        navigator: { clipboard: { async writeText(value) {
            if (!clipboardEnabled) throw new Error('Clipboard unavailable');
            copied.push(value);
        } } },
        setTimeout(callback, delay) { timers.set(++timerId, { callback, dueAt: now + delay }); return timerId; },
        clearTimeout(id) { timers.delete(id); },
        setInterval(callback, delay) { intervals.set(delay, callback); },
        async fetch(url, options) {
            requests.push([url, options]);
            const next = statuses.length ? statuses.shift() : initial;
            if (next instanceof Error) throw next;
            if (next === 409) return { ok: false, status: 409 };
            return { ok: true, json: async () => next };
        },
    });
    await flush();

    return {
        element: id => elements.get(id), labels, requests, copied, document,
        click: (id, event) => elements.get(id).listeners.click(event),
        async poll() { intervals.get(5000)(); await flush(); },
        async tick(milliseconds) {
            now += milliseconds;
            for (const [id, timer] of [...timers]) {
                if (timer.dueAt > now || !timers.delete(id)) continue;
                timer.callback();
            }
            intervals.get(1000)();
            await flush();
        },
    };
}

test('TRC20 显示地址二维码与精确应付数量，地址和金额可分别复制', async () => {
    const page = await checkout();
    assert.equal(page.element('payment-instructions').hidden, false);
    assert.equal(page.element('payment-address').value, recipient);
    assert.equal(page.element('payment-qr').src, ready.qr);
    assert.equal(page.element('quote-amount').value, '9007199254.740993');
    assert.equal(page.element('quote-asset').textContent, 'USDT');
    assert.equal(page.element('receipt-progress').value, 0);
    assert.equal(page.element('payment-qr-hint').textContent, page.labels.qrAddress);
    assert.equal(page.element('open-wallet').hidden, true);
    assert.equal(page.element('open-wallet').href, undefined);
    await page.click('copy-address');
    await page.click('copy-amount');
    assert.deepEqual(page.copied, [recipient, '9007199254.740993']);
    assert.equal(page.element('copy-feedback').textContent, page.labels.copied);
    assert.equal(page.requests.length, 2);
});

test('带金额二维码保留打开钱包的支付 URI', async () => {
    const page = await checkout({ initial: amountQr });
    assert.equal(page.element('open-wallet').hidden, false);
    assert.equal(page.element('open-wallet').href, amountQr.payment_uri);
    assert.equal(page.element('payment-qr-hint').textContent, page.labels.qrAmount);
});

test('重新报价为 TRC20 时清除之前的打开钱包入口', async () => {
    const page = await checkout({ statuses: [amountQr, amountQr, { ...ready, request_id: 2 }] });
    await page.poll();
    assert.equal(page.element('payment-address').value, recipient);
    assert.equal(page.element('payment-qr-hint').textContent, page.labels.qrAddress);
    assert.equal(page.element('open-wallet').hidden, true);
    assert.equal(page.element('open-wallet').href, undefined);
});

for (const state of ['checking', 'review', 'partial', 'confirming', 'paid', 'unavailable']) {
    test(`${state} 状态隐藏付款信息并停止复制`, async () => {
        const next = withoutPaymentDetails(amountQr, state);
        const page = await checkout({ initial: amountQr, statuses: [amountQr, amountQr, next] });
        await page.poll();
        assert.equal(page.element('payment-quote').hidden, true);
        assert.equal(page.element('payment-instructions').hidden, true);
        assert.equal(page.element('expired-address').textContent, '');
        assert.equal(page.element('payment-address').value, '');
        assert.equal(page.element('payment-qr').src, undefined);
        assert.equal(page.element('open-wallet').hidden, true);
        assert.equal(page.element('open-wallet').href, undefined);
        await page.click('copy-amount');
        await page.click('copy-address');
        assert.deepEqual(page.copied, []);
    });
}

test('状态接口失联时隐藏二维码和钱包入口', async () => {
    const page = await checkout({ initial: amountQr, statuses: [amountQr, amountQr, new Error('Offline')] });
    await page.poll();
    assert.equal(page.element('payment-status').textContent, page.labels.offline);
    assert.equal(page.element('payment-instructions').hidden, true);
    assert.equal(page.element('payment-address').value, '');
    assert.equal(page.element('payment-qr').src, undefined);
    assert.equal(page.element('open-wallet').href, undefined);
});

test('报价到期后即使接口仍返回旧 ready 状态也不展示付款信息', async () => {
    const page = await checkout({ initial: amountQr });
    await page.tick(900000);
    assert.equal(page.element('payment-instructions').hidden, true);
    assert.equal(page.element('payment-address').value, '');
    assert.equal(page.element('payment-qr').src, undefined);
    assert.equal(page.element('open-wallet').href, undefined);
    assert.equal(page.element('quote-time').textContent, '00:00:00');
    assert.equal(page.element('expired-address').textContent, amountQr.address);
    assert.equal(page.element('expired-amount').textContent, amountQr.due_amount);
    await page.click('copy-amount');
    assert.deepEqual(page.copied, []);
});

test('离开页面时隐藏旧付款信息', async () => {
    const page = await checkout({ initial: amountQr });
    page.document.hidden = true;
    page.document.visibilitychange();
    assert.equal(page.element('payment-instructions').hidden, true);
    assert.equal(page.element('payment-qr').src, undefined);
    assert.equal(page.element('open-wallet').href, undefined);
});

test('地址和金额准备期间显示加载框，报价就绪后开始时分秒倒计时', async () => {
    const checking = withoutPaymentDetails(ready, 'checking');
    const page = await checkout({ statuses: [checking, checking, ready] });
    assert.equal(page.element('payment-spinner').hidden, false);
    assert.equal(page.element('payment-quote').hidden, true);
    assert.equal(page.element('quote-countdown').hidden, true);

    await page.poll();
    assert.equal(page.element('payment-spinner').hidden, true);
    assert.equal(page.element('quote-time').textContent, '00:15:00');
    await page.tick(65000);
    assert.equal(page.element('quote-time').textContent, '00:13:55');
    assert.equal(page.requests.length, 3);
});

test('报价失效和重新生成期间划去旧信息，新报价接替后恢复钱包与复制', async () => {
    const expired = withoutPaymentDetails(amountQr, 'expired');
    const checking = withoutPaymentDetails(expired, 'checking');
    const replacement = {
        ...amountQr, request_id: 2, asset: 'USDC', due_amount: '40.000000',
        address: '0x' + 'c'.repeat(40), expires_at: new Date(startedAt + 1800000).toISOString(),
    };
    const page = await checkout({ statuses: [amountQr, amountQr, expired, checking, replacement] });

    await page.tick(900000);
    assert.equal(page.element('expired-address').textContent, amountQr.address);
    assert.equal(page.element('expired-amount').textContent, amountQr.due_amount);
    assert.equal(page.element('requote').hidden, false);
    assert.equal(page.element('copy-address').hidden, true);

    await page.click('requote');
    assert.deepEqual(JSON.parse(page.requests.at(-1)[1].body), { action: 'requote', request_id: 1 });
    assert.equal(page.element('payment-spinner').hidden, false);
    assert.equal(page.element('expired-address').textContent, amountQr.address);
    assert.equal(page.element('payment-qr').src, undefined);

    await page.poll();
    assert.equal(page.element('expired-address').hidden, true);
    assert.equal(page.element('quote-asset').textContent, 'USDC');
    assert.equal(page.element('quote-asset').dataset.asset, 'USDC');
    assert.equal(page.element('payment-address').value, replacement.address);
    assert.equal(page.element('quote-amount').value, replacement.due_amount);
    assert.equal(page.element('copy-address').hidden, false);
});

test('刷新同一订单仍显示已看过的过期地址，其他订单无法从存储读到该地址', async () => {
    const storage = new Map();
    await checkout({ initial: amountQr, storage });
    const expired = withoutPaymentDetails(amountQr, 'expired');
    const page = await checkout({ initial: expired, storage, now: startedAt + 900000 });
    assert.equal(page.element('expired-address').textContent, amountQr.address);
    assert.equal(page.element('payment-address').value, '');
    assert.equal(page.element('open-wallet').href, undefined);

    const other = await checkout({ initial: expired, storage, token: 'other', now: startedAt + 900000 });
    assert.equal(other.element('expired-address').textContent, '');
    assert.equal(other.element('expired-amount').textContent, amountQr.due_amount);
});

test('进度用已收到的精确数量计算，未付足一最小单位时也不会显示为收齐', async () => {
    const partial = {
        ...withoutPaymentDetails(amountQr, 'partial'), due_amount: '8.000000', received_amount: '2.000000',
    };
    const nearFull = {
        ...partial, due_amount: '9007199254.740993', received_amount: '9007199254.740992',
    };
    const page = await checkout({ statuses: [partial, nearFull, { ...nearFull, state: 'paid' }] });
    assert.equal(page.element('receipt-details').hidden, false);
    assert.equal(page.element('receipt-progress').value, 25);
    assert.equal(page.element('quote-amount').value, '');

    await page.poll();
    assert.equal(page.element('receipt-progress').value, 99);
    await page.poll();
    assert.equal(page.element('receipt-progress').value, 100);
});

test('复制权限不可用时选中值供手动复制', async () => {
    const page = await checkout({ clipboardEnabled: false });
    await page.click('copy-amount');
    assert.equal(page.element('quote-amount').selected, true);
    assert.equal(page.element('copy-feedback').textContent, page.labels.copyManually);
    await page.click('copy-address');
    assert.equal(page.element('payment-address').selected, true);
});

test('报价已被其他页面更换时读取新状态并使用新报价', async () => {
    const expired = withoutPaymentDetails(amountQr, 'expired');
    const replacement = { ...amountQr, request_id: 2, address: '0x' + 'c'.repeat(40) };
    const page = await checkout({ statuses: [expired, expired, 409, replacement] });

    await page.click('requote');

    assert.equal(page.requests.at(-1)[1].method, undefined);
    assert.equal(page.element('payment-address').value, replacement.address);
    assert.equal(page.element('expired-amount').hidden, true);
});

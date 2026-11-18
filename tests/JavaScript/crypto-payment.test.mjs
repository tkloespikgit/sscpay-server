import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../resources/js/crypto-payment.js', import.meta.url), 'utf8');
const recipient = 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL';
const ready = {
    state: 'ready', message: 'Ready', request_id: 1, can_requote: false,
    network: 'tron-mainnet', asset: 'USDT', due_amount: '9007199254.740993',
    expires_at: new Date(Date.now() + 900000).toISOString(), markup_percent: '2',
    received_amount: '0.000000', confirmed_amount: '0.000000', remaining_amount: '9007199254.740993',
    address: recipient, qr: 'data:image/svg+xml;base64,example', qr_type: 'address', payment_uri: recipient,
};
const amountQr = {
    ...ready, network: 'ethereum-mainnet', qr_type: 'amount', address: `0x${'a'.repeat(40)}`,
    payment_uri: `ethereum:0x${'b'.repeat(40)}@1/transfer?address=0x${'a'.repeat(40)}&uint256=9007199254740993`,
};
const flush = () => new Promise(resolve => setImmediate(resolve));

async function checkout({ initial = ready, statuses = [] } = {}) {
    const elements = new Map();
    const timers = new Map();
    const intervals = [];
    const requests = [];
    const copied = [];
    let timerId = 0;
    const node = () => ({
        hidden: false, disabled: false, textContent: '', value: '', listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        removeAttribute(name) { delete this[name]; },
        focus() {}, select() {},
    });
    for (const id of [
        'payment-instructions', 'payment-address', 'payment-qr', 'payment-qr-hint', 'payment-status', 'requote',
        'quote-details', 'quote-network', 'quote-expires', 'quote-received', 'quote-confirmed', 'quote-remaining',
        'quote-amount', 'quote-markup', 'open-wallet', 'check-payment', 'copy-address', 'copy-amount',
    ]) elements.set(id, node());
    const labels = {
        statusUrl: '/payment/token/crypto', openUrl: '/payment/token/crypto', markup: '__PERCENT__%',
        copied: 'Copied', offline: 'Offline', qrAmount: 'Amount QR', qrAddress: 'Address QR',
    };
    elements.set('crypto-checkout', { dataset: labels });
    let now = Date.now();
    class Clock extends Date { static now() { return now; } }
    const document = {
        hidden: false,
        getElementById: id => elements.get(id) ?? null,
        querySelector: () => ({ content: 'csrf' }),
        addEventListener(name, callback) { this[name] = callback; },
    };
    vm.runInNewContext(script, {
        document, Date: Clock, AbortSignal,
        navigator: { clipboard: { async writeText(value) { copied.push(value); } } },
        setTimeout(callback) { timers.set(++timerId, callback); return timerId; },
        clearTimeout(id) { timers.delete(id); },
        setInterval(callback) { intervals.push(callback); },
        async fetch(url, options) {
            requests.push([url, options]);
            const next = statuses.length ? statuses.shift() : initial;
            if (next instanceof Error) throw next;
            return { ok: true, json: async () => next };
        },
    });
    await flush();

    return {
        element: id => elements.get(id), labels, requests, copied, document,
        click: id => elements.get(id).listeners.click(),
        async poll() { intervals[0](); await flush(); },
        async expire() { now += 901000; [...timers.values()].at(-1)(); await flush(); },
    };
}

test('TRC20 显示地址二维码与精确应付数量，地址和金额可分别复制', async () => {
    const page = await checkout();
    assert.equal(page.element('payment-instructions').hidden, false);
    assert.equal(page.element('payment-address').value, recipient);
    assert.equal(page.element('payment-qr').src, ready.qr);
    assert.equal(page.element('quote-amount').textContent, '9007199254.740993 USDT');
    assert.equal(page.element('payment-qr-hint').textContent, page.labels.qrAddress);
    assert.equal(page.element('open-wallet').hidden, true);
    assert.equal(page.element('open-wallet').href, undefined);
    await page.click('copy-address');
    await page.click('copy-amount');
    assert.deepEqual(page.copied, [recipient, '9007199254.740993']);
    assert.equal(page.element('payment-status').textContent, page.labels.copied);
    assert.equal(page.requests.length, 2);
});

test('带金额二维码保留打开钱包的支付 URI', async () => {
    const page = await checkout({ initial: amountQr });
    assert.equal(page.element('open-wallet').hidden, false);
    assert.equal(page.element('open-wallet').href, amountQr.payment_uri);
    assert.equal(page.element('payment-qr-hint').textContent, page.labels.qrAmount);
});

test('重新报价为 TRC20 时清除之前的打开钱包入口', async () => {
    const page = await checkout({ statuses: [amountQr, amountQr, ready] });
    await page.poll();
    assert.equal(page.element('payment-address').value, recipient);
    assert.equal(page.element('payment-qr-hint').textContent, page.labels.qrAddress);
    assert.equal(page.element('open-wallet').hidden, true);
    assert.equal(page.element('open-wallet').href, undefined);
});

for (const state of ['checking', 'expired', 'review', 'partial', 'paid']) {
    test(`${state} 状态隐藏付款信息并停止复制`, async () => {
        const next = { ...ready, state, address: null, qr: null, qr_type: null, payment_uri: null };
        const page = await checkout({ initial: amountQr, statuses: [amountQr, amountQr, next] });
        await page.poll();
        assert.equal(page.element('payment-instructions').hidden, true);
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
    const page = await checkout();
    await page.expire();
    assert.equal(page.element('payment-instructions').hidden, true);
    assert.equal(page.element('payment-address').value, '');
    assert.equal(page.element('payment-qr').src, undefined);
});

test('离开页面时隐藏旧付款信息', async () => {
    const page = await checkout({ initial: amountQr });
    page.document.hidden = true;
    page.document.visibilitychange();
    assert.equal(page.element('payment-instructions').hidden, true);
    assert.equal(page.element('payment-qr').src, undefined);
    assert.equal(page.element('open-wallet').href, undefined);
});

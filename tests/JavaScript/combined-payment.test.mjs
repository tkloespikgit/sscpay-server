import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../resources/js/combined-payment.js', import.meta.url), 'utf8');
const complete = 'Your order has been paid in full.';
const paid = {
    status: 'paid', payable: false, currency: 'USD', amount: '4000.00', paid_amount: '4000.00',
    remaining_amount: '0.00', version: 2, current_payment: null, quote: null, children: [],
};

function checkout(initial, responses = []) {
    const elements = new Map();
    const timers = [];
    const redirects = [];
    const requests = [];
    const node = () => ({
        textContent: '', hidden: false, disabled: false, children: [], listeners: {}, style: {}, attributes: {}, dataset: {},
        clientWidth: 640, offsetWidth: 120, offsetHeight: 28,
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
    });
    for (const name of [
        'total', 'instructions', 'current', 'current-amount', 'pay', 'refresh', 'message', 'history', 'empty',
        'progress', 'progress-percentage', 'progress-hint', 'progress-bar', 'progress-markers',
        'progress-paid', 'progress-paid-marker', 'progress-paid-label', 'progress-paid-pointer',
        'progress-current', 'progress-current-marker', 'progress-current-label', 'progress-current-pointer',
        'progress-upcoming', 'progress-upcoming-marker', 'progress-upcoming-label', 'progress-upcoming-pointer',
    ]) {
        elements.set(`[data-${name}]`, node());
    }
    const root = {
        dataset: { config: JSON.stringify({
            state: initial, statusUrl: '/status', quoteUrl: '/quote', payUrl: '/pay',
            labels: {
                complete, pay: 'Pay this amount', continue: 'Continue this payment', loading: 'Processing',
                closed: 'This order cannot accept another payment.', waiting: 'Awaiting confirmation',
                part: 'Payment :number', states: { paid: 'Paid' }, errors: { NETWORK_ERROR: 'Connection unavailable.' },
                paid: 'Paid', progress_percentage: ':percent% paid', progress_current: 'Current', progress_upcoming: 'Remaining',
            },
        }) },
        querySelector: selector => elements.get(selector) ?? null,
    };
    vm.runInNewContext(script, {
        document: {
            querySelector: selector => selector === '[data-combined-checkout]' ? root : { content: 'csrf' },
            createElement: node,
            visibilityState: 'visible',
        },
        window: {
            location: { assign: url => redirects.push(url) },
            setTimeout: callback => timers.push(callback),
        },
        async fetch(url) {
            requests.push(url);
            const result = responses.shift();
            assert.notEqual(result, undefined, `Unexpected request: ${url}`);
            if (result instanceof Error) {
                throw result;
            }
            return { ok: !result.error, json: async () => result.error || ({ code: 0, data: result }) };
        },
    });
    return { element: name => elements.get(`[data-${name}]`), redirects, requests, poll: () => timers.shift()() };
}

test('已结清订单直接显示完成提示，不提供付款或返回商户入口', () => {
    const page = checkout(paid);
    assert.equal(page.element('message').textContent, complete);
    assert.equal(page.element('progress-paid').style.width, '100%');
    assert.equal(page.element('progress-percentage').textContent, '100% paid');
    assert.equal(page.element('progress-current-marker').hidden, true);
    assert.equal(page.element('progress-upcoming-marker').hidden, true);
    assert.equal(page.element('progress-paid-label').textContent, 'Paid: 4000.00');
    for (const name of ['pay', 'refresh', 'current', 'instructions']) {
        assert.equal(page.element(name).hidden, true, name);
    }
    assert.deepEqual(page.requests, []);
    assert.deepEqual(page.redirects, []);
});

test('最后一笔确认后切换为付清提示，不跳转商户', async () => {
    const page = checkout({
        ...paid, status: 'pending', payable: true, paid_amount: '3400.00', remaining_amount: '600.00', version: 1,
        current_payment: { amount: '600.00', payment_method: 'card' },
    }, [paid]);
    assert.equal(page.element('pay').hidden, false);

    await page.poll();

    assert.equal(page.element('message').textContent, complete);
    assert.equal(page.element('total').textContent, 'USD 4000.00');
    assert.equal(page.element('progress-paid').style.width, '100%');
    assert.equal(page.element('progress-current').hidden, true);
    assert.equal(page.element('progress-upcoming').hidden, true);
    for (const name of ['pay', 'refresh', 'current', 'instructions']) {
        assert.equal(page.element(name).hidden, true, name);
    }
    assert.deepEqual(page.requests, ['/status']);
    assert.deepEqual(page.redirects, []);
});

test('已确认付清后临时网络错误不会覆盖完成提示', async () => {
    const page = checkout(paid, [new Error('offline')]);

    await page.poll();

    assert.equal(page.element('message').textContent, complete);
    assert.equal(page.element('pay').hidden, true);
    assert.deepEqual(page.redirects, []);
});

test('进度按已到账、本笔待付和后续待付金额分段，已有子单优先于旧报价', () => {
    const page = checkout({
        ...paid, status: 'pending', payable: true, paid_amount: '1000.00', remaining_amount: '3000.00',
        current_payment: { amount: '600.00', payment_method: 'Private channel' },
        quote: { amount: '700.00', payment_method: 'Old channel' },
        children: [{ sequence: 1, merchant_order_no: 'PRIVATE-MERCHANT-001', payment_status: 'paid', amount: '1000.00' }],
    });

    assert.equal(page.element('progress-paid').style.width, '25%');
    assert.equal(page.element('progress-current').style.width, '15%');
    assert.equal(page.element('progress-upcoming').style.width, '60%');
    assert.equal(page.element('total').textContent, 'USD 4000.00');
    assert.equal(page.element('progress-paid-label').textContent, 'Paid: 1000.00');
    assert.equal(page.element('progress-current-label').textContent, 'Current: 600.00');
    assert.equal(page.element('progress-upcoming-label').textContent, 'Remaining: 2400.00');
    assert.equal(page.element('progress-percentage').textContent, '25% paid');
    assert.equal(page.element('progress-current-marker').hidden, false);
    assert.equal(page.element('progress-bar').attributes['aria-valuenow'], '25');
    assert.equal(page.element('progress-bar').attributes['aria-valuetext'], 'Paid: 1000.00; Current: 600.00; Remaining: 2400.00');
    assert.equal(page.element('history').children[0].children[0].children[0].textContent, 'Payment 1');
    assert.deepEqual(page.requests, []);
});

test('首次报价返回后显示本笔付款，后续待付金额精确到分', async () => {
    const initial = { ...paid, status: 'pending', payable: true, amount: '100.01', paid_amount: '0.00', remaining_amount: '100.01' };
    const page = checkout(initial, [{ ...initial, quote: { id: 'offer', amount: '33.34' } }]);
    assert.equal(page.element('progress-current').hidden, true);
    assert.equal(page.element('progress-upcoming').style.width, '100%');

    await new Promise(setImmediate);

    assert.equal(page.element('progress-current-label').textContent, 'Current: 33.34');
    assert.equal(page.element('progress-upcoming-label').textContent, 'Remaining: 66.67');
    assert.equal(page.element('progress-current-marker').hidden, false);
    assert.equal(page.element('pay').disabled, false);
    assert.deepEqual(page.requests, ['/quote']);
});

test('确认一笔到账后更新绿色进度并展示下一笔报价', async () => {
    const next = { ...paid, status: 'pending', payable: true, paid_amount: '1600.00', remaining_amount: '2400.00', version: 2 };
    const page = checkout({
        ...next, paid_amount: '1000.00', remaining_amount: '3000.00', version: 1,
        current_payment: { amount: '600.00' },
    }, [next, { ...next, quote: { id: 'next', amount: '700.00' } }]);

    await page.poll();

    assert.equal(page.element('progress-paid').style.width, '40%');
    assert.equal(page.element('progress-current').style.width, '17.5%');
    assert.equal(page.element('progress-upcoming').style.width, '42.5%');
    assert.equal(page.element('current-amount').textContent, 'USD 700.00');
    assert.equal(page.element('progress-upcoming-label').textContent, 'Remaining: 1700.00');
    assert.deepEqual(page.requests, ['/status', '/quote']);
});

test('报价失效时移除本笔标记，未付金额恢复为后续待付', async () => {
    const initial = { ...paid, status: 'pending', payable: true, paid_amount: '1000.00', remaining_amount: '3000.00' };
    const page = checkout(initial, [
        { ...initial, quote: { id: 'expired', amount: '600.00' } },
        { error: { error_code: 'QUOTE_EXPIRED', msg: 'Offer expired', data: initial } },
    ]);
    await new Promise(setImmediate);

    await page.element('pay').listeners.click();

    assert.equal(page.element('progress-paid').style.width, '25%');
    assert.equal(page.element('progress-current').hidden, true);
    assert.equal(page.element('progress-current-marker').hidden, true);
    assert.equal(page.element('progress-upcoming-label').textContent, 'Remaining: 3000.00');
    assert.equal(page.element('refresh').hidden, false);
    assert.equal(page.element('pay').disabled, true);
});

test('已关闭订单保留未决付款进度，但不会提示继续支付', () => {
    const page = checkout({
        ...paid, status: 'expired', paid_amount: '1000.00', remaining_amount: '3000.00',
        current_payment: { amount: '600.00' },
    });

    assert.equal(page.element('progress-paid').style.width, '25%');
    assert.equal(page.element('progress-current-label').textContent, 'Current: 600.00');
    assert.equal(page.element('progress-upcoming-label').textContent, 'Remaining: 2400.00');
    assert.equal(page.element('progress-hint').hidden, true);
    assert.equal(page.element('current').hidden, true);
    assert.equal(page.element('pay').hidden, true);
});

test('关闭订单不把旧报价展示为本笔待付', () => {
    const page = checkout({
        ...paid, status: 'review', paid_amount: '1000.00', remaining_amount: '3000.00',
        quote: { amount: '600.00' },
    });

    assert.equal(page.element('progress-current').hidden, true);
    assert.equal(page.element('progress-current-marker').hidden, true);
    assert.equal(page.element('progress-upcoming-label').textContent, 'Remaining: 3000.00');
});

test('最后一分钱未到账时不会显示百分之百已付', () => {
    const page = checkout({
        ...paid, status: 'pending', payable: true, amount: '100.00', paid_amount: '99.99', remaining_amount: '0.01',
        current_payment: { amount: '0.01' },
    });

    assert.equal(page.element('progress-percentage').textContent, '99% paid');
    assert.equal(page.element('progress-current-label').textContent, 'Current: 0.01');
    assert.equal(page.element('progress-upcoming-marker').hidden, true);
    assert.equal(page.element('progress-current-marker').hidden, false);
    assert.equal(page.element('pay').hidden, false);
});

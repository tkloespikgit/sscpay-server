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
        textContent: '', hidden: false, disabled: false, children: [], listeners: {},
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
    });
    for (const name of ['total', 'paid', 'remaining', 'instructions', 'current', 'current-amount', 'method', 'pay', 'refresh', 'message', 'history', 'empty']) {
        elements.set(`[data-${name}]`, node());
    }
    const root = {
        dataset: { config: JSON.stringify({
            state: initial, statusUrl: '/status', quoteUrl: '/quote', payUrl: '/pay',
            labels: {
                complete, pay: 'Pay this amount', continue: 'Continue this payment', loading: 'Processing',
                closed: 'This order cannot accept another payment.', waiting: 'Awaiting confirmation',
                part: 'Payment :number', states: { paid: 'Paid' }, errors: { NETWORK_ERROR: 'Connection unavailable.' },
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
            return { ok: true, json: async () => ({ code: 0, data: result }) };
        },
    });
    return { element: name => elements.get(`[data-${name}]`), redirects, requests, poll: () => timers.shift()() };
}

test('已结清订单直接显示完成提示，不提供付款或返回商户入口', () => {
    const page = checkout(paid);
    assert.equal(page.element('message').textContent, complete);
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
    assert.equal(page.element('remaining').textContent, 'USD 0.00');
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

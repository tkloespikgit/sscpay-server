import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/checkout/show.blade.php', import.meta.url), 'utf8');
const start = blade.indexOf('<script data-checkout-email>');
const script = blade.slice(blade.indexOf('function checkoutEmailTrim', start), blade.indexOf('</script>', start));

function page() {
    const email = { value: '' };
    const error = { hidden: true, textContent: '', dataset: { message: 'Enter a valid email address' } };
    const button = { disabled: false, textContent: 'Pay', dataset: { payLabel: 'Pay', submittingLabel: 'Processing' } };
    let stopped = 0;
    const form = {
        listeners: [],
        addEventListener(name, callback) { this.listeners.push([name, callback]); },
    };
    const context = vm.createContext({
        document: {
            getElementById: id => ({ 'checkout-form': form, email, 'checkout-email-error': error, 'checkout-submit': button })[id],
        },
        TextEncoder,
    });
    vm.runInContext(script, context);
    return {
        context,
        email,
        error,
        button,
        submit() {
            const event = {
                preventDefault() { event.prevented = true; },
                stopImmediatePropagation() { stopped += 1; },
                prevented: false,
            };
            form.listeners.filter(([name]) => name === 'submit').forEach(([, callback]) => callback(event));
            return { ...event, stopped };
        },
    };
}

test('插件会拒绝的邮箱在页面规则中同样不通过', () => {
    const { context } = page();
    for (const email of ['name@gmail', 'a@b.c', '用户@gmail.com', '"john"@gmail.com', 'john@gmail..com', 'john@-example.com']) {
        assert.equal(vm.runInContext(`checkoutEmailAccepted(${JSON.stringify(email)})`, context), false, email);
    }
    for (const email of ['john@example.com', 'john+tag@gmail.com']) {
        assert.equal(vm.runInContext(`checkoutEmailAccepted(${JSON.stringify(email)})`, context), true, email);
    }
});

test('不合规邮箱停在收款页并恢复付款按钮', () => {
    const view = page();
    view.button.disabled = true;
    view.button.textContent = 'Processing';
    view.email.value = 'name@gmail';
    const result = view.submit();
    assert.equal(result.prevented, true);
    assert.equal(result.stopped, 1);
    assert.equal(view.button.disabled, false);
    assert.equal(view.button.textContent, 'Pay');
    assert.equal(view.error.hidden, false);
    assert.equal(view.error.textContent, 'Enter a valid email address');
});

test('合规邮箱不拦截提交', () => {
    const view = page();
    view.email.value = 'john@example.com';
    const result = view.submit();
    assert.equal(result.prevented, false);
    assert.equal(result.stopped, 0);
    assert.equal(view.error.hidden, true);
});

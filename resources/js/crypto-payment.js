const root = document.getElementById('crypto-checkout');

if (root) {
    const element = (id) => document.getElementById(id);
    const instructions = element('payment-instructions');
    let current = null;
    let busy = false;
    let lastOpen = 0;
    let expiresTimer;

    function hidePayment() {
        instructions.hidden = true;
        element('payment-address').value = '';
        element('payment-qr').removeAttribute('src');
        clearTimeout(expiresTimer);
    }

    function render(data) {
        current = data;
        hidePayment();
        element('payment-status').textContent = data.message;
        element('requote').hidden = !data.can_requote;
        element('quote-details').hidden = !data.request_id;
        const merchantReturn = element('merchant-return');
        if (merchantReturn) merchantReturn.hidden = data.state !== 'paid';
        if (data.request_id) {
            element('quote-network').textContent = `${data.network} / ${data.asset}`;
            element('quote-expires').textContent = new Date(data.expires_at).toLocaleString();
            for (const field of ['received', 'confirmed', 'remaining']) {
                element(`quote-${field}`).textContent = `${data[`${field}_amount`]} ${data.asset}`;
            }
        }
        const remaining = Date.parse(data.expires_at) - Date.now();
        if (data.state === 'ready' && data.address && data.qr && remaining > 0) {
            element('quote-amount').textContent = `${data.due_amount} ${data.asset}`;
            element('quote-markup').textContent = root.dataset.markup.replace('__PERCENT__', data.markup_percent);
            element('payment-address').value = data.address;
            element('payment-qr').src = data.qr;
            instructions.hidden = false;
            expiresTimer = setTimeout(() => { hidePayment(); refresh('open'); }, Math.min(remaining, 2147483647));
        }
    }

    async function json(url, options = {}) {
        const response = await fetch(url, {
            ...options, cache: 'no-store', credentials: 'same-origin', signal: AbortSignal.timeout(10000),
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        });
        if (!response.ok) throw new Error('Payment status unavailable');
        return response.json();
    }

    async function refresh(action = null) {
        if (busy) return;
        busy = true;
        element('requote').disabled = true;
        element('check-payment').disabled = true;
        try {
            if (!current) current = await json(root.dataset.statusUrl);
            let data;
            if (action && !['paid', 'review', 'partial', 'confirming', 'unavailable'].includes(current.state)) {
                hidePayment();
                lastOpen = Date.now();
                data = await json(root.dataset.openUrl, { method: 'POST',
                    body: JSON.stringify({ action, request_id: current.request_id }) });
            } else {
                data = await json(root.dataset.statusUrl);
            }
            render(data);
        } catch {
            hidePayment();
            element('requote').hidden = true;
            current = null;
            element('payment-status').textContent = root.dataset.offline;
        } finally {
            busy = false;
            element('requote').disabled = false;
            element('check-payment').disabled = false;
        }
    }

    element('requote').addEventListener('click', () => refresh('requote'));
    element('check-payment').addEventListener('click', () => refresh('open'));
    element('copy-address').addEventListener('click', async () => {
        if (instructions.hidden) return;
        try {
            await navigator.clipboard.writeText(element('payment-address').value);
            element('payment-status').textContent = root.dataset.copied;
        } catch {
            element('payment-address').focus();
            element('payment-address').select();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) hidePayment();
        else refresh('open');
    });
    refresh('open');
    setInterval(() => {
        if (!document.hidden && current?.state !== 'paid') {
            refresh((!current || current.state === 'checking') && Date.now() - lastOpen >= 30000 ? 'open' : null);
        }
    }, 5000);
}

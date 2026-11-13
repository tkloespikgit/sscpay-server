const root = document.querySelector('[data-combined-checkout]');

if (root) {
    const config = JSON.parse(root.dataset.config);
    const labels = config.labels;
    let state = config.state;
    let busy = false;
    let quoteFailed = false;
    let message = '';
    const element = (name) => root.querySelector('[data-' + name + ']');
    const money = (amount) => state.currency + ' ' + amount;

    function render() {
        element('total').textContent = money(state.amount);
        element('paid').textContent = money(state.paid_amount);
        element('remaining').textContent = money(state.remaining_amount);
        const current = state.current_payment || state.quote;
        element('current').hidden = !current || !state.payable;
        element('current-amount').textContent = current ? money(current.amount) : '—';
        element('method').textContent = current?.payment_method || '';
        element('pay').hidden = !state.payable;
        element('pay').disabled = busy || !current || (quoteFailed && !state.current_payment);
        element('pay').textContent = busy ? labels.loading : (state.current_payment ? labels.continue : labels.pay);
        element('refresh').hidden = !state.payable || busy || (!quoteFailed && !!current);
        element('message').textContent = message || (state.status === 'paid' ? labels.complete
            : (!state.payable ? labels.closed : (state.current_payment ? labels.waiting : '')));
        const returnLink = element('return');
        returnLink.hidden = state.status !== 'paid' || !state.return_url;
        if (!returnLink.hidden) {
            returnLink.href = state.return_url;
        }
        const history = element('history');
        history.replaceChildren();
        for (const child of state.children) {
            const row = document.createElement('li');
            row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4';
            const detail = document.createElement('div');
            const title = document.createElement('p');
            title.className = 'text-sm font-medium';
            title.textContent = labels.part.replace(':number', child.sequence) + ' · ' + child.merchant_order_no;
            const status = document.createElement('p');
            status.className = 'mt-1 text-sm text-slate-500';
            status.textContent = labels.states[child.payment_status] || child.payment_status;
            const amount = document.createElement('span');
            amount.className = 'font-semibold tabular-nums';
            amount.textContent = money(child.amount);
            detail.append(title, status);
            row.append(detail, amount);
            history.append(row);
        }
        element('empty').hidden = state.children.length > 0;
    }

    async function request(url, body) {
        const response = await fetch(url, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                ...(body ? {'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content} : {}),
            },
            ...(body ? {body: JSON.stringify(body)} : {}),
        });
        const result = await response.json();
        if (result.data) {
            state = result.data;
        }
        if (!response.ok) {
            const error = new Error(result.msg || labels.errors.NETWORK_ERROR);
            error.code = result.error_code;
            throw error;
        }
        return result;
    }

    async function prepare() {
        if (busy || !state.payable || state.current_payment) {
            return;
        }
        busy = true;
        message = '';
        render();
        try {
            await request(config.quoteUrl, {});
            quoteFailed = false;
        } catch (error) {
            message = error.message || labels.errors.NETWORK_ERROR;
            quoteFailed = true;
        } finally {
            busy = false;
            render();
        }
    }

    element('refresh').addEventListener('click', prepare);
    element('pay').addEventListener('click', async () => {
        if (busy || !state.payable) {
            return;
        }
        busy = true;
        message = '';
        render();
        try {
            const result = await request(config.payUrl, {quote_id: state.quote?.id || null});
            if (result.pay_url) {
                window.location.assign(result.pay_url);
            }
        } catch (error) {
            message = error.message || labels.errors.NETWORK_ERROR;
            if (error.code === 'QUOTE_EXPIRED') {
                state.quote = null;
                quoteFailed = true;
            }
        } finally {
            busy = false;
            render();
        }
    });

    async function poll() {
        if (!busy && document.visibilityState !== 'hidden') {
            try {
                const previousVersion = state.version;
                await request(config.statusUrl);
                if (state.version > previousVersion) {
                    message = '';
                    quoteFailed = false;
                }
                render();
                if (state.payable && !state.current_payment && !state.quote && !quoteFailed) {
                    await prepare();
                }
            } catch {
                message = labels.errors.NETWORK_ERROR;
                render();
            }
        }
        window.setTimeout(poll, 5000);
    }

    render();
    if (state.payable && !state.current_payment) {
        prepare();
    }
    window.setTimeout(poll, 5000);
}

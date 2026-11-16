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

    function minorUnits(amount) {
        const [whole, fraction = ''] = String(amount ?? '0').split('.');
        return Number(whole) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2));
    }

    function decimalAmount(amount) {
        return Math.floor(amount / 100) + '.' + String(amount % 100).padStart(2, '0');
    }

    function positionProgressMarkers() {
        const container = element('progress-markers');
        const width = container.clientWidth;
        if (!width) {
            return;
        }

        const rows = [];
        const markers = [];
        let height = 0;
        for (const name of ['paid', 'current', 'upcoming']) {
            const marker = element('progress-' + name + '-marker');
            if (marker.hidden) {
                continue;
            }
            const anchor = Number(marker.dataset.position) / 100 * width;
            const left = Math.max(0, Math.min(width - marker.offsetWidth, anchor - marker.offsetWidth / 2));
            let row = rows.findIndex(right => left >= right + 8);
            if (row === -1) {
                row = rows.length;
            }
            rows[row] = left + marker.offsetWidth;
            height = Math.max(height, marker.offsetHeight);
            marker.style.left = left + 'px';
            element('progress-' + name + '-pointer').style.left = Math.max(4, Math.min(marker.offsetWidth - 4, anchor - left)) + 'px';
            markers.push({marker, row});
        }
        for (const {marker, row} of markers) {
            marker.style.bottom = row * (height + 10) + 'px';
        }
        container.style.height = markers.length ? (rows.length - 1) * (height + 10) + height + 'px' : '0px';
    }

    function renderProgress(current) {
        const total = minorUnits(state.amount);
        const paid = Math.min(total, Math.max(0, minorUnits(state.paid_amount)));
        const currentAmount = Math.min(total - paid, Math.max(0, minorUnits(current?.amount)));
        const upcoming = total - paid - currentAmount;
        const percentage = (amount) => total > 0 ? amount / total * 100 : 0;
        const paidPercentage = percentage(paid);

        element('progress').hidden = false;
        element('progress-percentage').textContent = labels.progress_percentage.replace(':percent', Math.floor(paidPercentage));
        element('progress-hint').hidden = !state.payable || !current;
        element('progress-bar').setAttribute('aria-valuenow', paidPercentage);
        const descriptions = [];
        let offset = 0;
        for (const [name, amount, label] of [['paid', paid, labels.paid], ['current', currentAmount, labels.progress_current], ['upcoming', upcoming, labels.progress_upcoming]]) {
            const description = label + ': ' + decimalAmount(amount);
            const marker = element('progress-' + name + '-marker');
            element('progress-' + name + '-label').textContent = description;
            marker.hidden = amount === 0;
            marker.dataset.position = percentage(offset + amount / 2);
            element('progress-' + name).style.width = percentage(amount) + '%';
            element('progress-' + name).hidden = amount === 0;
            descriptions.push(description);
            offset += amount;
        }
        element('progress-bar').setAttribute('aria-valuetext', descriptions.join('; '));
        positionProgressMarkers();
    }

    function render() {
        element('total').textContent = money(state.amount);
        element('instructions').hidden = state.status === 'paid';
        const current = state.current_payment || (state.payable && !quoteFailed ? state.quote : null);
        renderProgress(current);
        element('current').hidden = !current || !state.payable;
        element('current-amount').textContent = current ? money(current.amount) : '—';
        element('pay').hidden = !state.payable;
        element('pay').disabled = busy || !current || (quoteFailed && !state.current_payment);
        element('pay').textContent = busy ? labels.loading : (state.current_payment ? labels.continue : labels.pay);
        element('refresh').hidden = !state.payable || busy || (!quoteFailed && !!current);
        element('message').textContent = state.status === 'paid' ? labels.complete
            : (message || (!state.payable ? labels.closed : (state.current_payment ? labels.waiting : '')));
        const history = element('history');
        history.replaceChildren();
        for (const child of state.children) {
            const row = document.createElement('li');
            row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4';
            const detail = document.createElement('div');
            const title = document.createElement('p');
            title.className = 'text-sm font-medium';
            title.textContent = labels.part.replace(':number', child.sequence);
            const status = document.createElement('p');
            status.className = 'mt-1 text-sm text-slate-500';
            status.textContent = labels.states[child.payment_status] || child.payment_status;
            const amount = document.createElement('span');
            amount.className = child.payment_status === 'paid'
                ? 'font-semibold tabular-nums text-emerald-700'
                : 'font-semibold tabular-nums text-zinc-700';
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
    if (typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(positionProgressMarkers).observe(element('progress-bar'));
    }
    if (state.payable && !state.current_payment) {
        prepare();
    }
    window.setTimeout(poll, 5000);
}

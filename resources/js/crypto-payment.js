const root = document.getElementById('crypto-checkout');

if (root) {
    const element = (id) => document.getElementById(id);
    const qr = element('payment-qr');
    const wallet = element('open-wallet');
    const copyFeedback = element('copy-feedback');
    const storageKey = 'crypto-checkout:' + root.dataset.statusUrl;
    const readOnlyStates = ['paid', 'review', 'partial', 'confirming', 'unavailable'];
    let displayedQuote = readDisplayedQuote();
    let activeQuote = null;
    let current = null;
    let busy = false;
    let lastOpen = 0;
    let countdownUntil = 0;
    let expiresTimer;
    let copyTimer;

    function readDisplayedQuote() {
        try {
            return JSON.parse(sessionStorage.getItem(storageKey));
        } catch {
            return null;
        }
    }

    function rememberQuote({ request_id, expires_at, asset, asset_label, due_amount, network_label, address }) {
        if (displayedQuote?.request_id === request_id) return;

        displayedQuote = { request_id, expires_at, asset, asset_label, due_amount, network_label, address };
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(displayedQuote));
        } catch {
            // The open page can still show the previous quote when storage is disabled.
        }
    }

    function clearCopyFeedback() {
        clearTimeout(copyTimer);
        copyFeedback.hidden = true;
        copyFeedback.textContent = '';
    }

    function renderValue(input, expired, value, payable) {
        input.hidden = !payable;
        const currentValue = payable ? value : '';
        if (input.value !== currentValue) {
            input.value = currentValue;
            input.title = currentValue;
        }
        expired.hidden = payable || !value;
        expired.textContent = expired.hidden ? '' : value;
        expired.title = expired.textContent;
    }

    function renderQuote(quote, payable) {
        if (!payable || quote.request_id !== activeQuote?.request_id) clearCopyFeedback();
        activeQuote = payable ? quote : null;

        element('payment-quote').hidden = !quote;
        element('quote-asset').textContent = quote?.asset_label || quote?.asset || '';
        element('quote-asset').dataset.asset = quote?.asset ?? '';
        renderValue(element('quote-amount'), element('expired-amount'), quote?.due_amount ?? '', payable);
        element('copy-amount').hidden = !payable;

        element('payment-recipient').hidden = !quote?.address;
        element('quote-network').textContent = quote?.network_label || '';
        renderValue(element('payment-address'), element('expired-address'), quote?.address ?? '', payable);
        element('copy-address').hidden = !payable;
        element('payment-instructions').hidden = !payable;
        if (payable) {
            if (qr.src !== quote.qr) qr.src = quote.qr;
            element('payment-qr-hint').textContent = quote.qr_type === 'amount'
                ? root.dataset.qrAmount : root.dataset.qrAddress;
        } else {
            qr.removeAttribute('src');
        }

        const walletUri = activeQuote?.qr_type === 'amount' ? activeQuote.payment_uri : null;
        wallet.hidden = !walletUri;
        if (walletUri) wallet.href = walletUri;
        else wallet.removeAttribute('href');
    }

    function decimalQuantity(amount) {
        const match = /^(0|[1-9]\d*)(?:\.(\d+))?$/.exec(amount ?? '');
        if (!match) return null;

        return { value: BigInt(match[1] + (match[2] ?? '')), scale: (match[2] ?? '').length };
    }

    function receivedPercent(data) {
        if (data.state === 'paid') return 100;

        const due = decimalQuantity(data.due_amount);
        const received = decimalQuantity(data.received_amount);
        if (!due || !received || due.value <= 0n || received.value <= 0n) return 0;

        // ETH can show 8 places while an off-grid receipt still shows 18.
        const scale = Math.max(due.scale, received.scale);
        const dueUnits = due.value * 10n ** BigInt(scale - due.scale);
        const receivedUnits = received.value * 10n ** BigInt(scale - received.scale);
        if (receivedUnits >= dueUnits) return 100;

        return Math.max(1, Number(receivedUnits * 100n / dueUnits));
    }

    function updateCountdown() {
        if (element('quote-countdown').hidden) return;

        const seconds = Math.max(0, Math.ceil((countdownUntil - Date.now()) / 1000));
        const parts = [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60];
        element('quote-time').textContent = parts.map(part => String(part).padStart(2, '0')).join(':');
    }

    function render(data) {
        if (document.hidden) return;

        const now = Date.now();
        const expiresAt = Date.parse(data.expires_at);
        const payable = data.state === 'ready' && Boolean(data.due_amount && data.address && data.qr) && expiresAt > now;
        const expired = data.state === 'expired' || (data.state === 'ready' && expiresAt <= now);
        const checking = data.state === 'checking' || (data.state === 'ready' && !payable && !expired);
        const showPrevious = displayedQuote && (
            (expired && displayedQuote.request_id === data.request_id)
            || (checking && Date.parse(displayedQuote.expires_at) <= now)
        );
        const previous = showPrevious ? displayedQuote : (expired && data.due_amount ? { ...data, address: null } : null);
        const quote = payable ? data : previous;

        if (payable) rememberQuote(data);
        renderQuote(quote, payable);
        element('requote').hidden = !data.can_requote;
        const merchantReturn = element('merchant-return');
        if (merchantReturn) merchantReturn.hidden = data.state !== 'paid';

        const statusLine = element('payment-status-line');
        statusLine.hidden = payable;
        statusLine.dataset.state = checking ? 'checking' : (expired ? 'expired' : data.state);
        element('payment-spinner').hidden = !checking;
        element('payment-status').textContent = checking ? root.dataset.preparing : (expired ? root.dataset.expired : data.message);

        const showProgress = Boolean(quote || data.state === 'paid' || (data.request_id && ['confirming', 'partial', 'review'].includes(data.state)));
        element('receipt-details').hidden = !showProgress;
        element('receipt-progress').value = showProgress ? receivedPercent(data) : 0;

        clearTimeout(expiresTimer);
        countdownUntil = payable ? expiresAt : 0;
        element('quote-countdown').hidden = !quote && !expired;
        updateCountdown();
        if (payable) {
            expiresTimer = setTimeout(() => {
                render(current);
                refresh(null);
            }, Math.min(expiresAt - now, 2147483647));
        }
    }

    async function json(url, options = {}) {
        const response = await fetch(url, {
            ...options, cache: 'no-store', credentials: 'same-origin', signal: AbortSignal.timeout(10000),
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        });
        if (response.status === 409 && options.method === 'POST') return json(root.dataset.statusUrl);
        if (!response.ok) throw new Error('Payment status unavailable');
        return response.json();
    }

    async function refresh(action = null) {
        if (busy || document.hidden) return;
        busy = true;
        element('requote').disabled = true;
        element('check-payment').disabled = true;
        try {
            let data = action && current && !readOnlyStates.includes(current.state)
                ? current : await json(root.dataset.statusUrl);
            const canSubmit = action === 'requote' ? data.can_requote : action === 'open' && !readOnlyStates.includes(data.state);
            if (canSubmit) {
                render({ ...data, state: 'checking', can_requote: false });
                lastOpen = Date.now();
                data = await json(root.dataset.openUrl, { method: 'POST',
                    body: JSON.stringify({ action, request_id: data.request_id }) });
            }
            current = data;
            render(data);
        } catch {
            current = null;
            render({ state: 'offline', message: root.dataset.offline });
        } finally {
            busy = false;
            element('requote').disabled = false;
            element('check-payment').disabled = false;
        }
    }

    function quoteIsPayable() {
        return activeQuote && !document.hidden && Date.parse(activeQuote.expires_at) > Date.now();
    }

    async function copyValue(id) {
        if (!quoteIsPayable()) return;
        const requestId = activeQuote.request_id;
        const input = element(id);
        let message = root.dataset.copied;
        try {
            await navigator.clipboard.writeText(input.value);
        } catch {
            if (!quoteIsPayable() || activeQuote.request_id !== requestId) return;
            input.focus();
            input.select();
            message = root.dataset.copyManually;
        }
        if (!quoteIsPayable() || activeQuote.request_id !== requestId) return;

        clearCopyFeedback();
        copyFeedback.hidden = false;
        copyFeedback.textContent = message;
        copyTimer = setTimeout(clearCopyFeedback, 2500);
    }

    element('requote').addEventListener('click', () => refresh('requote'));
    element('check-payment').addEventListener('click', () => refresh(null));
    element('copy-amount').addEventListener('click', () => copyValue('quote-amount'));
    element('copy-address').addEventListener('click', () => copyValue('payment-address'));
    wallet.addEventListener('click', (event) => {
        if (!quoteIsPayable()) event.preventDefault();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(expiresTimer);
            element('quote-countdown').hidden = true;
            renderQuote(null, false);
        } else {
            refresh(null);
        }
    });

    refresh('open');
    setInterval(() => {
        if (!document.hidden) updateCountdown();
    }, 1000);
    setInterval(() => {
        if (!document.hidden && current?.state !== 'paid') {
            const needsOpen = !current || current.state === 'checking';
            refresh(needsOpen && Date.now() - lastOpen >= 30000 ? 'open' : null);
        }
    }, 5000);
}

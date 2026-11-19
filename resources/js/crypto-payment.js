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

    function rememberQuote({ request_id, expires_at, asset, due_amount, network, address }) {
        if (displayedQuote?.request_id === request_id) return;

        displayedQuote = { request_id, expires_at, asset, due_amount, network, address };
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
        element('quote-asset').textContent = quote?.asset ?? '';
        element('quote-asset').dataset.asset = quote?.asset ?? '';
        renderValue(element('quote-amount'), element('expired-amount'), quote?.due_amount ?? '', payable);
        element('copy-amount').hidden = !payable;

        element('payment-recipient').hidden = !quote?.address;
        element('quote-network').textContent = quote?.network ?? '';
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

    function receivedPercent(data) {
        if (data.state === 'paid') return 100;
        if (!data.due_amount || !data.received_amount) return 0;

        // The presenter formats both amounts at the same asset precision.
        const due = BigInt(data.due_amount.replace('.', ''));
        const received = BigInt(data.received_amount.replace('.', ''));
        if (due <= 0n || received <= 0n) return 0;
        return received >= due ? 100 : Math.max(1, Number(received * 100n / due));
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
                refresh('open');
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
    element('check-payment').addEventListener('click', () => refresh('open'));
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
            refresh('open');
        }
    });

    refresh('open');
    setInterval(() => {
        if (!document.hidden) updateCountdown();
    }, 1000);
    setInterval(() => {
        if (!document.hidden && current?.state !== 'paid') {
            const needsOpen = !current || current.state === 'checking'
                || (current.state === 'ready' && Date.parse(current.expires_at) <= Date.now());
            refresh(needsOpen && Date.now() - lastOpen >= 30000 ? 'open' : null);
        }
    }, 5000);
}

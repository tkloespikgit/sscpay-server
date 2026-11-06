{{-- Crypto checkout: amount due = converted_amount (thousandths). QR encodes receive address only. --}}
@php
    $symbol = $order->crypto_symbol ?: 'USDT';
    $chain = strtoupper((string) ($order->crypto_chain ?? ''));
    $scale = $order->cryptoAmountScale();
    $expectedRaw = (string) ($order->converted_amount ?? '0');
    $expected = is_numeric($expectedRaw) ? bcadd($expectedRaw, '0', $scale) : bcadd('0', '0', $scale);
    $expiresAt = $order->crypto_expires_at;
    $isPaid = $order->status === 'paid';
    $address = (string) ($order->crypto_receive_address ?? '');
    $merchantName = (string) ($order->merchant->name ?? 'Invoice');
    $badgeType = $isPaid ? 'paid' : 'warning';
    $badgeLabel = $isPaid ? 'Paid' : 'Pending';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $merchantName }} · {{ $order->order_no }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @unless($isPaid)
        <meta http-equiv="refresh" content="30">
    @endunless
    <style>
        :root {
            --pga-bg: #0b1020; --pga-card: #121a2e; --pga-inset: #0f1627; --pga-line: #243150; --pga-line2: #1f2a44; --pga-chip: #1b2540;
            --pga-ink: #e8ecf5; --pga-muted: #93a0b8; --pga-body: #c3cbdb; --pga-accent: #c6f432;
            --pga-warning-bg: #1d1a10; --pga-warning-line: #3f3515; --pga-warning-ink: #f5c451; --pga-warning-soft: #e3cf96; --pga-warning-dot: #f5c451; --pga-warning-pill-bg: #2a2412;
            --pga-paid-bg: #131f12; --pga-paid-line: #24401f; --pga-paid-ink: #8fe25c; --pga-paid-soft: #b9dd9c; --pga-paid-dot: #8fe25c; --pga-paid-pill-bg: #1c2a13;
            --pga-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            --pga-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        * { box-sizing: border-box; }
        body.pga-payment-page {
            margin: 0; min-height: 100vh; background: var(--pga-bg); color: var(--pga-ink);
            font-family: var(--pga-sans); font-variant-numeric: tabular-nums;
        }
        .pga-mono { font-family: var(--pga-mono); }
        .pga-payment-container {
            width: 100%; max-width: 560px; margin: 48px auto; padding: 32px 28px;
            background: var(--pga-card); border: 1px solid var(--pga-line); border-radius: 20px;
            display: flex; flex-direction: column; gap: 22px;
        }
        .pga-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .pga-brand { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .pga-logo {
            width: 40px; height: 40px; border-radius: 10px; background: var(--pga-chip);
            display: grid; place-items: center; color: var(--pga-accent); flex-shrink: 0;
        }
        .pga-head h1 { margin: 0; font-size: 20px; font-weight: 600; color: var(--pga-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pga-status-badge {
            display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 999px;
            background: var(--pga-warning-pill-bg); color: var(--pga-warning-ink); font-size: 13px; font-weight: 500; white-space: nowrap;
        }
        .pga-status-badge i { width: 7px; height: 7px; border-radius: 50%; background: var(--pga-warning-dot); }
        .pga-status-badge--paid { background: var(--pga-paid-pill-bg); color: var(--pga-paid-ink); }
        .pga-status-badge--paid i { background: var(--pga-paid-dot); }
        .pga-notice {
            display: flex; gap: 14px; align-items: center; padding: 14px 18px;
            background: var(--pga-warning-bg); border: 1px solid var(--pga-warning-line); border-radius: 12px;
            color: var(--pga-warning-ink); font-size: 14px; line-height: 1.5;
        }
        .pga-notice svg { flex-shrink: 0; }
        .pga-notice strong { font-weight: 600; }
        .pga-notice span { color: var(--pga-warning-soft); }
        .pga-notice--paid { background: var(--pga-paid-bg); border-color: var(--pga-paid-line); color: var(--pga-paid-ink); }
        .pga-notice--paid span { color: var(--pga-paid-soft); }
        .pga-k { font-size: 12px; color: var(--pga-muted); margin: 0 0 6px; }
        .pga-v { font-size: 13px; word-break: break-all; margin: 0; color: var(--pga-ink); }
        .pga-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .pga-box { padding: 16px; border-radius: 14px; background: var(--pga-inset); border: 1px solid var(--pga-line2); }
        .pga-amount {
            padding: 20px; border-radius: 14px; background: var(--pga-inset); border: 1px solid var(--pga-line2);
            text-align: center;
        }
        .pga-amount .pga-num { font-size: 36px; font-weight: 700; letter-spacing: -.03em; color: var(--pga-accent); line-height: 1.1; }
        .pga-amount .pga-num small { font-size: 16px; font-weight: 600; margin-left: 6px; }
        .pga-countdown { margin: 10px 0 0; font-size: 13px; color: var(--pga-muted); }
        .pga-countdown strong { color: var(--pga-warning-ink); font-weight: 600; }
        .pga-qr-wrap { display: inline-block; padding: 12px; border-radius: 12px; background: #fff; border: 1px solid var(--pga-line2); line-height: 0; }
        .pga-qr-wrap svg { width: 180px; height: 180px; display: block; }
        .pga-qr-block { display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .pga-copy {
            border: 0; background: transparent; color: var(--pga-accent); font: inherit;
            font-size: 12px; font-weight: 600; cursor: pointer; padding: 0;
        }
        .pga-pay-button {
            font: inherit; cursor: pointer; border-radius: 12px; text-decoration: none;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            height: 52px; border: 0; background: var(--pga-accent); color: var(--pga-bg);
            font-size: 16px; font-weight: 700; padding: 10px; width: 100%; box-sizing: border-box;
        }
        .pga-pay-button:hover { filter: brightness(1.08); }
        .pga-pay-button:focus-visible { outline: 3px solid var(--pga-accent); outline-offset: 2px; }
        #copy-toast {
            position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); z-index: 20;
            background: var(--pga-ink); color: var(--pga-bg); padding: 8px 16px; border-radius: 999px; font-size: 13px;
        }
        @media (max-width: 600px) {
            .pga-payment-container {
                margin: 0; min-height: 100vh; border: 0; border-radius: 0; padding: 24px 16px 40px;
            }
            .pga-amount .pga-num { font-size: 32px; }
        }
    </style>
</head>
<body class="pga-payment-page">
    <main class="pga-payment-container">
        <div class="pga-head">
            <div class="pga-brand">
                <span class="pga-logo" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 3 2V2l-3 2-3-2-3 2-3-2-3 2z"/><path d="M8 9h8"/><path d="M8 13h6"/></svg>
                </span>
                <h1>{{ $merchantName }}</h1>
            </div>
            <span class="pga-status-badge pga-status-badge--{{ $badgeType }}"><i></i>{{ $badgeLabel }}</span>
        </div>

        @if($isPaid)
            <div class="pga-notice pga-notice--paid" role="status">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></svg>
                <div>
                    <strong>Payment received.</strong>
                    <span>On-chain amount confirmed.</span>
                </div>
            </div>
        @else
            <div class="pga-notice" role="status">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <div>
                    <strong>Awaiting payment.</strong>
                    <span>
                        Time left
                        <strong class="pga-mono" data-countdown data-expires="{{ optional($expiresAt)?->toIso8601String() }}">--:--:--</strong>
                    </span>
                </div>
            </div>
        @endif

        <div>
            <p class="pga-k">Order number</p>
            <p class="pga-v pga-mono">{{ $order->order_no }}</p>
        </div>

        <div class="pga-pair">
            <div>
                <p class="pga-k">Network</p>
                <p class="pga-v">{{ $chain !== '' ? $chain : '—' }}</p>
            </div>
            <div>
                <p class="pga-k">Currency</p>
                <p class="pga-v">{{ $symbol }}</p>
            </div>
        </div>

        <div class="pga-amount">
            <p class="pga-k">Amount due</p>
            <div class="pga-num pga-mono">{{ $expected }}<small>{{ $symbol }}</small></div>
        </div>

        <div class="pga-box">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:6px">
                <p class="pga-k" style="margin:0">Receive address</p>
                @unless($isPaid)
                    <button type="button" class="pga-copy js-copy" data-copy="{{ $address }}">Copy</button>
                @endunless
            </div>
            <p class="pga-v pga-mono">{{ $address !== '' ? $address : '—' }}</p>
        </div>

        @if(! $isPaid && $address !== '')
            <div class="pga-qr-block">
                <p class="pga-k">Payment QR</p>
                <div class="pga-qr-wrap" id="crypto-qr" role="img" aria-label="Payment QR code"></div>
            </div>
        @endif

        @if(! $isPaid && ! empty($deepLink))
            <a class="pga-pay-button" href="{{ $deepLink }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                {{ $walletLabel ?: 'Open in wallet' }}
            </a>
        @elseif($isPaid && $order->return_url)
            <a class="pga-pay-button" href="{{ $order->return_url }}">Return to merchant</a>
        @endif
    </main>

    <div id="copy-toast" hidden>Copied</div>

    @unless($isPaid)
        <script src="{{ asset('js/vendor/qrcode-generator.js') }}"></script>
    @endunless
    <script>
        (() => {
            const toast = document.getElementById('copy-toast');
            let toastTimer;
            document.querySelectorAll('.js-copy').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const text = btn.getAttribute('data-copy') || '';
                    try {
                        await navigator.clipboard.writeText(text);
                    } catch (e) {
                        const area = document.createElement('textarea');
                        area.value = text;
                        document.body.appendChild(area);
                        area.select();
                        document.execCommand('copy');
                        area.remove();
                    }
                    if (toast) {
                        toast.hidden = false;
                        clearTimeout(toastTimer);
                        toastTimer = setTimeout(() => { toast.hidden = true; }, 1400);
                    }
                });
            });

            const el = document.querySelector('[data-countdown]');
            if (el) {
                const expiresAt = Date.parse(el.getAttribute('data-expires') || '');
                if (!Number.isFinite(expiresAt)) {
                    el.textContent = '—';
                } else {
                    const tick = () => {
                        const left = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
                        const h = String(Math.floor(left / 3600)).padStart(2, '0');
                        const m = String(Math.floor((left % 3600) / 60)).padStart(2, '0');
                        const s = String(left % 60).padStart(2, '0');
                        el.textContent = left === 0 ? 'Expired' : `${h}:${m}:${s}`;
                        if (left === 0) {
                            clearInterval(timer);
                            setTimeout(() => window.location.reload(), 1200);
                        }
                    };
                    tick();
                    var timer = setInterval(tick, 1000);
                }
            }

            const address = @json($address);
            const mount = document.getElementById('crypto-qr');
            if (!mount || !address || typeof qrcode !== 'function') return;

            try {
                const qr = qrcode(0, 'M');
                qr.addData(address);
                qr.make();
                mount.innerHTML = qr.createSvgTag(4, 2);
            } catch (e) {
                mount.textContent = 'QR unavailable — please copy the address above';
                mount.style.lineHeight = '1.4';
                mount.style.color = '#0b1020';
                mount.style.fontSize = '12px';
                mount.style.padding = '12px';
            }
        })();
    </script>
</body>
</html>

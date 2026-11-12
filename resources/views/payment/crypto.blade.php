<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('crypto.checkout.title') }} — {{ $order->order_no }}</title>
    @vite(['resources/css/app.css', 'resources/js/crypto-payment.js'])
</head>
<body class="checkout-page bg-slate-50 text-slate-900 antialiased">
    <main id="crypto-checkout" class="mx-auto flex max-w-2xl flex-col gap-6 px-5 py-10"
        data-status-url="{{ route('payment.crypto.status', $order->payment_link_token, false) }}"
        data-open-url="{{ route('payment.crypto.open', $order->payment_link_token, false) }}"
        data-offline="{{ __('crypto.checkout.offline') }}" data-copied="{{ __('crypto.checkout.copied') }}"
        data-markup="{{ __('crypto.checkout.markup', ['percent' => '__PERCENT__']) }}">
        <header class="flex flex-col gap-2">
            <p class="text-sm text-slate-600">{{ $order->merchant?->name }}</p>
            <h1 class="text-2xl font-semibold">{{ __('crypto.checkout.title') }}</h1>
            <p class="break-all text-sm text-slate-500">{{ __('crypto.checkout.order') }}: {{ $order->order_no }}</p>
            <p class="text-lg">{{ __('crypto.checkout.original') }}: {{ $order->currency }} {{ $order->amount }}</p>
        </header>
        <p id="payment-status" role="status" aria-live="polite" class="rounded-xl border border-slate-200 bg-white p-5">{{ __('crypto.checkout.states.checking') }}</p>
        <noscript><p>{{ __('crypto.checkout.no_js') }}</p></noscript>
        <section id="quote-details" hidden class="rounded-xl border border-slate-200 bg-white p-6">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach (['network', 'expires', 'received', 'confirmed', 'remaining'] as $key)
                    <div><dt class="text-sm text-slate-500">{{ __('crypto.checkout.'.$key) }}</dt><dd id="quote-{{ $key }}" class="break-all font-medium"></dd></div>
                @endforeach
            </dl>
        </section>
        <section id="payment-instructions" hidden class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-sm text-slate-500">{{ __('crypto.checkout.due') }}</h2>
            <p id="quote-amount" class="break-all text-2xl font-semibold"></p>
            <p id="quote-markup" class="text-sm text-slate-600"></p>
            <label for="payment-address" class="text-sm font-medium">{{ __('crypto.checkout.address') }}</label>
            <textarea id="payment-address" readonly rows="2" class="w-full resize-none rounded-lg border border-slate-300 p-3 font-mono text-sm"></textarea>
            <button id="copy-address" type="button" class="rounded-lg border border-slate-300 px-4 py-2">{{ __('crypto.checkout.copy') }}</button>
            <img id="payment-qr" alt="{{ __('crypto.checkout.address') }}" class="mx-auto h-52 w-52" width="208" height="208">
            <p class="text-sm leading-6 text-slate-600">{{ __('crypto.checkout.instructions') }}</p>
        </section>
        <div class="flex flex-wrap gap-3">
            <button id="requote" hidden type="button" class="rounded-lg bg-slate-900 px-5 py-3 font-semibold text-white disabled:opacity-50">{{ __('crypto.checkout.requote') }}</button>
            <button id="check-payment" type="button" class="rounded-lg border border-slate-300 bg-white px-5 py-3 disabled:opacity-50">{{ __('crypto.checkout.check') }}</button>
            @if (in_array(parse_url((string) $order->return_url, PHP_URL_SCHEME), ['http', 'https'], true))
                <a id="merchant-return" hidden href="{{ $order->return_url }}" rel="noreferrer" class="rounded-lg bg-slate-900 px-5 py-3 font-semibold text-white">{{ __('crypto.checkout.return') }}</a>
            @endif
        </div>
    </main>
</body>
</html>

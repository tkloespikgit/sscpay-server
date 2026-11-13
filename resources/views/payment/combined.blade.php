<!DOCTYPE html>
<html lang="{{ app()->getLocale() === 'zh_CN' ? 'zh-CN' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('combined.title') }} · {{ $parent->merchant->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/combined-payment.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto max-w-3xl px-5 py-10 sm:py-16" data-combined-checkout data-config="{{ json_encode([
        'state' => $state,
        'statusUrl' => route('combined-payment.status', $parent->payment_link_token),
        'quoteUrl' => route('combined-payment.quote', $parent->payment_link_token),
        'payUrl' => route('combined-payment.pay', $parent->payment_link_token),
        'labels' => __('combined'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
        <header class="mb-8 flex items-start justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">{{ $parent->merchant->name }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ __('combined.title') }}</h1>
                <p class="mt-2 break-all text-sm text-slate-500">{{ __('combined.order') }}: {{ $parent->merchant_order_no }}</p>
            </div>
            <nav class="flex gap-3 text-sm" aria-label="Language">
                <a href="{{ route('combined-payment.show', ['token' => $parent->payment_link_token, 'lang' => 'zh_CN']) }}" lang="zh-CN" class="underline underline-offset-4">中文</a>
                <a href="{{ route('combined-payment.show', ['token' => $parent->payment_link_token, 'lang' => 'en']) }}" lang="en" class="underline underline-offset-4">English</a>
            </nav>
        </header>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="max-w-xl text-sm leading-6 text-slate-600">{{ __('combined.description') }}</p>
            <dl class="mt-7 grid grid-cols-1 gap-5 border-b border-slate-100 pb-7 sm:grid-cols-3" aria-live="polite">
                <div><dt class="text-sm text-slate-500">{{ __('combined.total') }}</dt><dd class="mt-2 text-xl font-semibold tabular-nums" data-total>{{ $parent->currency }} {{ $parent->amount }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('combined.paid') }}</dt><dd class="mt-2 text-xl font-semibold tabular-nums text-emerald-700" data-paid>{{ $parent->currency }} {{ $parent->paid_amount }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('combined.remaining') }}</dt><dd class="mt-2 text-xl font-semibold tabular-nums" data-remaining>{{ $parent->currency }} {{ $parent->remaining_amount }}</dd></div>
            </dl>
            <div class="mt-6" data-current>
                <p class="text-sm text-slate-500">{{ __('combined.current') }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums" data-current-amount>—</p>
                <p class="mt-2 text-sm text-slate-500" data-method></p>
            </div>
            <p class="mt-5 text-sm leading-6" role="status" aria-live="polite" data-message></p>
            <button type="button" disabled data-pay class="mt-6 w-full rounded-xl bg-slate-900 px-5 py-3.5 font-semibold text-white transition hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-slate-900 disabled:cursor-wait disabled:opacity-50">{{ __('combined.loading') }}</button>
            <button type="button" hidden data-refresh class="mt-4 w-full rounded-xl border border-slate-300 px-5 py-3 font-medium hover:bg-slate-50">{{ __('combined.prepare') }}</button>
            <a hidden data-return class="mt-6 block rounded-xl bg-emerald-700 px-5 py-3.5 text-center font-semibold text-white hover:bg-emerald-800">{{ __('combined.return') }}</a>
            <noscript><p class="mt-4">{{ __('combined.noscript') }}</p></noscript>
        </section>
        <section class="mt-8" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-base font-semibold">{{ __('combined.history') }}</h2>
            <ol class="mt-4 space-y-3" data-history></ol>
            <p class="mt-4 text-sm text-slate-500" data-empty>{{ __('combined.empty') }}</p>
        </section>
    </main>
</body>
</html>

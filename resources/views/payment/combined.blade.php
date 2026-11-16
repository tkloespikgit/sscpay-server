<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('combined.title') }}</title>
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
                <h1 class="text-2xl font-semibold tracking-tight">{{ __('combined.title') }}</h1>
                <p class="mt-2 break-all text-sm text-slate-500">{{ __('combined.order') }}: {{ $parent->order_no }}</p>
            </div>
        </header>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5" data-instructions @if($state['status'] === 'paid') hidden @endif>
                <h2 class="text-sm font-semibold">{{ __('combined.description_title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('combined.description') }}</p>
            </div>
            <p class="mt-7 flex flex-wrap items-baseline gap-x-2 gap-y-1" aria-live="polite">
                <span class="text-sm text-slate-500">{{ __('combined.total') }}:</span>
                <span class="text-xl font-semibold tabular-nums sm:text-2xl" data-total>{{ $parent->currency }} {{ $parent->amount }}</span>
            </p>
            <section class="mt-7 border-b border-slate-100 pb-7" aria-labelledby="progress-heading" data-progress hidden>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="progress-heading" class="text-base font-semibold">{{ __('combined.progress') }}</h2>
                    <p class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold tabular-nums text-emerald-700" data-progress-percentage></p>
                </div>
                <div class="mt-5">
                    <div class="relative mb-2" data-progress-markers aria-live="polite" aria-atomic="true">
                        <p class="absolute w-max max-w-full rounded-md bg-emerald-700 px-2 py-1.5 text-xs font-semibold wrap-break-word tabular-nums text-white" data-progress-paid-marker hidden>
                            <span data-progress-paid-label></span>
                            <span class="absolute top-full -translate-x-1/2 border-4 border-transparent border-t-emerald-700" data-progress-paid-pointer aria-hidden="true"></span>
                        </p>
                        <p class="absolute w-max max-w-full rounded-md bg-zinc-700 px-2 py-1.5 text-xs font-semibold wrap-break-word tabular-nums text-white" data-progress-current-marker hidden>
                            <span data-progress-current-label></span>
                            <span class="absolute top-full -translate-x-1/2 border-4 border-transparent border-t-zinc-700" data-progress-current-pointer aria-hidden="true"></span>
                        </p>
                        <p class="absolute w-max max-w-full rounded-md bg-gray-200 px-2 py-1.5 text-xs font-semibold wrap-break-word tabular-nums text-gray-700" data-progress-upcoming-marker hidden>
                            <span data-progress-upcoming-label></span>
                            <span class="absolute top-full -translate-x-1/2 border-4 border-transparent border-t-gray-200" data-progress-upcoming-pointer aria-hidden="true"></span>
                        </p>
                    </div>
                    <div class="flex h-6 overflow-hidden rounded-full bg-gray-200 ring-1 ring-slate-900/5 sm:h-7" role="progressbar" aria-labelledby="progress-heading" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-progress-bar>
                        <div class="h-full shrink-0 bg-emerald-500 transition-[width] duration-500 motion-reduce:transition-none" data-progress-paid></div>
                        <div class="h-full shrink-0 bg-zinc-700 transition-[width] duration-500 motion-reduce:transition-none" data-progress-current></div>
                        <div class="h-full shrink-0 bg-gray-200 transition-[width] duration-500 motion-reduce:transition-none" data-progress-upcoming></div>
                    </div>
                </div>
                <p class="mt-4 text-sm leading-6 text-slate-600" data-progress-hint>{{ __('combined.progress_hint') }}</p>
            </section>
            <div class="mt-6 rounded-xl border-s-4 border-zinc-700 bg-zinc-50 p-5" data-current @if(! $state['payable']) hidden @endif>
                <p class="text-sm font-medium text-zinc-600">{{ __('combined.current') }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums" data-current-amount>—</p>
            </div>
            <p class="mt-5 text-sm leading-6" role="status" aria-live="polite" data-message>{{ $state['status'] === 'paid' ? __('combined.complete') : '' }}</p>
            <button type="button" disabled data-pay @if(! $state['payable']) hidden @endif class="mt-6 w-full rounded-xl bg-slate-900 px-5 py-3.5 font-semibold text-white transition hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-slate-900 disabled:cursor-wait disabled:opacity-50">{{ __('combined.loading') }}</button>
            <button type="button" hidden data-refresh class="mt-4 w-full rounded-xl border border-slate-300 px-5 py-3 font-medium hover:bg-slate-50">{{ __('combined.prepare') }}</button>
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

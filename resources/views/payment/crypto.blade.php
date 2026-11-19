<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $order->merchant_order_no }} — {{ __('crypto.checkout.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/crypto-payment.js'])
</head>
<body class="checkout-page min-h-screen bg-slate-50 antialiased dark:bg-slate-950">
    <main id="crypto-checkout" class="mx-auto w-full max-w-lg px-3 py-6 text-slate-900 sm:px-6 sm:py-12 dark:text-slate-100"
        data-status-url="{{ route('payment.crypto.status', $order->payment_link_token, false) }}"
        data-open-url="{{ route('payment.crypto.open', $order->payment_link_token, false) }}"
        data-offline="{{ __('crypto.checkout.offline') }}" data-copied="{{ __('crypto.checkout.copied') }}"
        data-preparing="{{ __('crypto.checkout.preparing') }}"
        data-expired="{{ __('crypto.checkout.states.expired') }}"
        data-qr-amount="{{ __('crypto.checkout.qr_amount') }}"
        data-qr-address="{{ __('crypto.checkout.qr_address') }}"
        data-copy-manually="{{ __('crypto.checkout.copy_manually') }}">
        <article class="flex flex-col gap-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7 dark:border-slate-800 dark:bg-slate-900">
            <header class="grid gap-1.5 border-b border-slate-100 pb-4 dark:border-slate-700">
                <p class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ $order->merchant?->name }}</p>
                <h1 class="text-xl font-semibold tracking-tight wrap-anywhere sm:text-2xl"><bdi>{{ $order->merchant_order_no }}</bdi></h1>
            </header>

            <dl class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <dt class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('crypto.checkout.original') }}</dt>
                    <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ $order->currency }} {{ $order->amount }}</bdi></dd>
                </div>
                <div id="quote-countdown" hidden class="text-end">
                    <dt class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('crypto.checkout.countdown') }}</dt>
                    <dd id="quote-time" role="timer" dir="ltr" class="mt-1 font-mono text-sm font-semibold text-amber-800 dark:text-amber-300">00:00:00</dd>
                </div>
            </dl>
            <div role="status" class="contents">
                <div id="payment-status-line" data-state="checking" class="flex items-center gap-3 rounded-xl bg-slate-100 px-3 py-2.5 text-sm text-slate-700 data-[state=checking]:min-h-32 data-[state=checking]:justify-center data-[state=checking]:text-center data-[state=expired]:bg-amber-50 data-[state=expired]:text-amber-900 dark:bg-slate-800 dark:text-slate-200 dark:data-[state=expired]:bg-amber-950 dark:data-[state=expired]:text-amber-200">
                    <span id="payment-spinner" aria-hidden="true" class="size-5 shrink-0 rounded-full border-2 border-slate-300 border-t-slate-700 motion-safe:animate-spin dark:border-slate-600 dark:border-t-slate-200"></span>
                    <p id="payment-status">{{ __('crypto.checkout.preparing') }}</p>
                </div>
            </div>
            <noscript>
                <style>#payment-status-line { display: none }</style>
                <p class="text-sm">{{ __('crypto.checkout.no_js') }}</p>
            </noscript>

            <div id="payment-quote" hidden class="grid gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <label for="quote-amount" class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ __('crypto.checkout.due') }}</label>
                    <bdi id="quote-asset" class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800 data-[asset=USDT]:bg-emerald-100 data-[asset=USDT]:text-emerald-900 data-[asset=USDC]:bg-sky-100 data-[asset=USDC]:text-sky-900 dark:bg-slate-800 dark:text-slate-100 dark:data-[asset=USDT]:bg-emerald-950 dark:data-[asset=USDT]:text-emerald-200 dark:data-[asset=USDC]:bg-sky-950 dark:data-[asset=USDC]:text-sky-200"></bdi>
                </div>
                <div class="flex items-center gap-1">
                    <input id="quote-amount" type="text" dir="ltr" readonly class="min-w-0 flex-1 truncate rounded-sm bg-transparent text-start text-2xl font-semibold tracking-tight tabular-nums focus:text-clip focus-visible:outline-2 focus-visible:outline-blue-600">
                    <del id="expired-amount" hidden dir="ltr" class="min-w-0 flex-1 truncate text-2xl font-semibold tabular-nums text-slate-500 dark:text-slate-400"></del>
                    <button id="copy-amount" type="button" aria-label="{{ __('crypto.checkout.copy_amount') }}" title="{{ __('crypto.checkout.copy_amount') }}" class="grid size-8 shrink-0 cursor-pointer place-items-center rounded-md text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-blue-600 dark:text-slate-300 dark:hover:bg-slate-800">
                        <x-heroicon-o-document-duplicate aria-hidden="true" class="size-4" />
                    </button>
                </div>
            </div>

            <div id="receipt-details" hidden class="grid gap-2">
                <div aria-hidden="true" class="flex items-center justify-between gap-2 text-xs font-medium">
                    <span class="text-emerald-800 dark:text-emerald-300">{{ __('crypto.checkout.received') }}</span>
                    <span class="text-slate-600 dark:text-slate-300">{{ __('crypto.checkout.not_received') }}</span>
                </div>
                <progress id="receipt-progress" max="100" value="0" aria-label="{{ __('crypto.checkout.progress') }}" class="block h-2 w-full appearance-none overflow-hidden rounded-full bg-slate-200 [&::-moz-progress-bar]:rounded-full [&::-moz-progress-bar]:bg-emerald-600 [&::-webkit-progress-bar]:bg-slate-200 [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-emerald-600 dark:bg-slate-700 dark:[&::-webkit-progress-bar]:bg-slate-700"></progress>
            </div>

            <div id="payment-recipient" hidden class="grid gap-1.5">
                <div class="flex flex-wrap items-baseline justify-between gap-1.5">
                    <label for="payment-address" class="text-sm font-medium">{{ __('crypto.checkout.address') }}</label>
                    <bdi id="quote-network" class="text-xs font-medium text-slate-600 dark:text-slate-300"></bdi>
                </div>
                <div class="flex min-h-10 items-center gap-1 rounded-lg border border-slate-200 px-2 dark:border-slate-700">
                    <input id="payment-address" type="text" dir="ltr" readonly class="min-w-0 flex-1 truncate rounded-sm bg-transparent font-mono text-xs focus:text-clip focus-visible:outline-2 focus-visible:outline-blue-600">
                    <del id="expired-address" hidden dir="ltr" class="min-w-0 flex-1 truncate font-mono text-xs text-slate-500 dark:text-slate-400"></del>
                    <button id="copy-address" type="button" aria-label="{{ __('crypto.checkout.copy_address') }}" title="{{ __('crypto.checkout.copy_address') }}" class="grid size-8 shrink-0 cursor-pointer place-items-center rounded-md text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-blue-600 dark:text-slate-300 dark:hover:bg-slate-800">
                        <x-heroicon-o-document-duplicate aria-hidden="true" class="size-4" />
                    </button>
                </div>
            </div>
            <div role="status" class="contents">
                <p id="copy-feedback" hidden class="text-xs font-medium text-slate-700 dark:text-slate-300"></p>
            </div>

            <div id="payment-instructions" hidden class="grid gap-2">
                <p id="payment-qr-hint" class="text-center text-xs leading-relaxed text-slate-600 dark:text-slate-300"></p>
                <img id="payment-qr" alt="{{ __('crypto.checkout.payment_qr') }}" class="mx-auto size-44 rounded-lg bg-white sm:size-48" width="192" height="192">
                <ol class="list-decimal space-y-1 ps-5 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                    @foreach (__('crypto.checkout.instructions') as $instruction)
                        <li>{{ $instruction }}</li>
                    @endforeach
                </ol>
            </div>

            <div class="flex gap-2.5 text-center text-sm font-semibold [&>*]:flex-1">
                <a id="open-wallet" hidden class="flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-2.5 py-2 text-white hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">{{ __('crypto.checkout.open_wallet') }}</a>
                <button id="requote" hidden type="button" class="min-h-11 cursor-pointer rounded-xl bg-slate-900 px-2.5 py-2 text-white hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">{{ __('crypto.checkout.requote') }}</button>
                <button id="check-payment" type="button" disabled class="min-h-11 cursor-pointer rounded-xl border border-slate-200 px-2.5 py-2 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800">{{ __('crypto.checkout.check') }}</button>
                @if (in_array(parse_url((string) $order->return_url, PHP_URL_SCHEME), ['http', 'https'], true))
                    <a id="merchant-return" hidden href="{{ $order->return_url }}" rel="noreferrer" class="flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-2.5 py-2 text-white hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">{{ __('crypto.checkout.return') }}</a>
                @endif
            </div>
        </article>
    </main>
</body>
</html>

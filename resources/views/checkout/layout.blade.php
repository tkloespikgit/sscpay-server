{{--
    收款链接落地页的外壳。

    独立于 Filament 面板：这是面向终端客户的公开页面，不能带任何后台的
    样式、脚本或导航。语言固定英文（lang="en"），不跟随 APP_LOCALE ——
    落地页只提供英文，见 lang/en/checkout.php 的说明。

    noindex：收款链接不应该被搜索引擎收录。slug 虽然不可猜测，但一旦被
    爬虫抓走并建了索引，链接就等于公开了。
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title')</title>
    @vite(['resources/css/app.css'])
    @stack('head')
</head>
<body class="checkout-page h-full bg-white text-slate-900 antialiased">
    <div class="mx-auto flex min-h-full max-w-6xl flex-col px-5 py-6 sm:px-8 lg:px-10 lg:py-10">
        <header class="checkout-header mb-10 flex items-center gap-4 border-b border-slate-200 pb-6 lg:mb-12">
            @if ($link->logoUrl())
                <img src="{{ $link->logoUrl() }}" alt="" class="h-10 w-auto max-w-[160px] object-contain">
            @endif
            <div class="min-w-0">
                <p class="mb-0.5 text-xs font-medium uppercase tracking-[0.18em] text-slate-500">{{ __('checkout.sections.secure_checkout') }}</p>
                <h1 class="truncate text-xl font-semibold tracking-tight sm:text-2xl">{{ $link->title }}</h1>
            </div>
        </header>

        <main class="flex-1">
            @yield('content')
        </main>

        <footer class="mt-14 flex items-center justify-center gap-2 border-t border-slate-200 pt-6 text-xs text-slate-500">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd" />
            </svg>
            {{ __('checkout.footer.secure') }}
        </footer>
    </div>

    @stack('scripts')
</body>
</html>

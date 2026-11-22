<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="分别查看 SSCPay 网关站和商城站需要领取的配置、安装步骤、沙箱验收和回调密钥排查方法。">
    <title>{{ __('plugins.installation_guide', [], 'zh_CN') }} · SSCPay</title>
    @vite('resources/css/app.css')
</head>
<body id="top" class="bg-white font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <a href="#guide" class="sr-only rounded-lg bg-slate-900 px-4 py-3 text-white focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50">跳到安装教程</a>
    <header class="border-b border-slate-200 dark:border-slate-800 print:hidden">
        <nav aria-label="站点导航" class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-4 sm:px-8">
            <a class="guide-link flex items-center gap-2.5 font-semibold tracking-tight" href="{{ route('home.show') }}" aria-label="SSCPay 首页">
                <span aria-hidden="true" class="grid size-8 place-items-center rounded-lg bg-emerald-700 font-mono text-base font-bold text-white">S</span> SSCPay
            </a>
            <a class="guide-link text-sm font-medium" href="{{ route('plugins.index') }}"><span aria-hidden="true">←</span> 插件下载</a>
        </nav>
    </header>

    <main id="guide" tabindex="-1" class="mx-auto max-w-5xl px-5 pt-9 pb-12 sm:px-8 sm:pt-14">
        <p class="flex flex-wrap items-center gap-3 text-xs font-semibold tracking-wide text-slate-600 dark:text-slate-400 print:hidden">
            <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">接入手册</span>
            WooCommerce / OpenCart
        </p>
        <article class="plugin-guide">{{ $guide }}</article>
        <footer aria-label="教程相关资源" class="mt-12 border-t border-slate-200 pt-6 dark:border-slate-800 print:hidden">
            <p class="mb-3 text-sm font-semibold">获取安装包</p>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-4 text-sm">
                <a class="guide-link" href="{{ route('plugins.index') }}#platform-wordpress">WooCommerce 商城插件</a>
                <a class="guide-link" href="{{ route('plugins.index') }}#platform-opencart">OpenCart 商城插件</a>
                @if (request()->isSecure())
                    <a class="guide-link" href="{{ route('plugins.gateway.download') }}">网关主插件（公开包）</a>
                @endif
                <a class="guide-link sm:ms-auto" href="#top">返回顶部 <span aria-hidden="true">↑</span></a>
            </div>
        </footer>
    </main>
</body>
</html>

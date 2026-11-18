@php
    $platforms = __('plugins.platforms');
    $pluginsByPlatform = collect($plugins)->groupBy('platform');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() === 'zh_CN' ? 'zh-CN' : 'en' }}" class="scroll-smooth motion-reduce:scroll-auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('plugins.intro') }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ __('plugins.title') }} · SSCPay</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <a href="#plugin-downloads" class="sr-only z-50 rounded-lg bg-slate-950 px-4 py-3 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:start-4 focus:top-4 dark:bg-white dark:text-slate-950">
        {{ __('plugins.skip_to_content') }}
    </a>

    <header id="top" class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm dark:border-slate-800 dark:bg-slate-950/95">
        <div class="mx-auto flex max-w-7xl flex-col items-start justify-between gap-3 px-4 py-4 sm:flex-row sm:items-center sm:gap-4 sm:px-6 lg:px-8">
            <a href="#top" class="flex min-w-0 items-center gap-3 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-700 font-mono text-sm font-semibold text-white" aria-hidden="true">↓</span>
                <span class="truncate text-sm font-semibold tracking-tight sm:text-base">SSCPay <span class="ms-2 font-normal text-slate-500 dark:text-slate-400">{{ __('plugins.title') }}</span></span>
            </a>
            <nav aria-label="{{ __('plugins.resources') }}" class="flex items-center gap-2 self-end text-xs font-medium sm:self-auto">
                <a href="{{ route('home.show') }}" class="rounded-lg px-3 py-2.5 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">{{ __('plugins.home') }}</a>
                <a href="{{ route('api.documentation', ['version' => '2.0']) }}" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-slate-700 transition-colors hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('plugins.docs') }}</a>
            </nav>
        </div>
    </header>

    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-7 sm:px-6 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-12 lg:px-8 lg:py-10">
        <aside class="hidden min-w-0 lg:block">
            <nav aria-label="{{ __('plugins.platform_navigation') }}" class="sticky top-28 flex max-h-[calc(100vh-9rem)] flex-col gap-5 overflow-y-auto pb-8">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">{{ __('plugins.platform_navigation') }}</p>
                @include('plugins.platform-navigation')
                <p class="px-4 text-xs leading-6 text-slate-500 dark:text-slate-400">{{ __('plugins.navigation_hint') }}</p>
            </nav>
        </aside>

        <main id="plugin-downloads" class="min-w-0 scroll-mt-40 sm:scroll-mt-24" tabindex="-1">
            <div class="mb-10 flex flex-col gap-5 border-b border-slate-200 pb-8 dark:border-slate-800 sm:mb-12">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('plugins.title') }}</h1>
                <p class="max-w-3xl text-base leading-8 text-slate-600 dark:text-slate-400">{{ __('plugins.intro') }}</p>
            </div>

            <details class="mb-8 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 lg:hidden" data-mobile-platform-navigation>
                <summary class="cursor-pointer px-5 py-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-emerald-600">{{ __('plugins.platform_navigation') }}</summary>
                <nav aria-label="{{ __('plugins.platform_navigation') }}" class="border-t border-slate-100 px-3 py-3 dark:border-slate-800">
                    @include('plugins.platform-navigation')
                </nav>
            </details>

            <div class="flex flex-col gap-12 sm:gap-16">
                @foreach ($platforms as $platform => $label)
                    @php($platformPlugins = $pluginsByPlatform->get($platform, collect()))
                    <section id="platform-{{ $platform }}" aria-labelledby="{{ $platform }}-title" class="min-w-0 scroll-mt-40 sm:scroll-mt-28">
                        <div class="mb-6 flex flex-wrap items-center gap-3">
                            <span class="rounded-md bg-emerald-100 px-2 py-1 font-mono text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <h2 id="{{ $platform }}-title" class="text-xl font-semibold tracking-tight sm:text-2xl">{{ $label }}</h2>
                            <span class="rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">{{ $platformPlugins->isNotEmpty() ? __('plugins.plugin_count', ['count' => $platformPlugins->count()]) : __('plugins.planned') }}</span>
                        </div>

                        @if ($platformPlugins->isNotEmpty())
                            <div class="grid gap-5 sm:grid-cols-2">
                                @foreach ($platformPlugins as $plugin)
                                    <article class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                                        <div class="mb-4 flex flex-wrap items-center gap-2 text-xs font-medium">
                                            <span class="rounded-md bg-emerald-50 px-2 py-1 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">API {{ $plugin['release']['api_version'] ?? $plugin['api_version'] }}</span>
                                            @if ($plugin['release'])
                                                <span class="rounded-md bg-slate-100 px-2 py-1 text-slate-600 dark:bg-slate-800 dark:text-slate-300">v{{ $plugin['release']['version'] }}</span>
                                            @endif
                                        </div>
                                        <h3 class="text-base leading-7 font-semibold tracking-tight">{{ $plugin['name'] }}</h3>
                                        <p class="mt-3 text-sm leading-7 text-slate-600 dark:text-slate-400">{{ $plugin['description'] }}</p>
                                        @if ($plugin['release'])
                                            <dl class="mt-5 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 border-t border-slate-100 pt-4 text-xs leading-6 dark:border-slate-800">
                                                <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                                                <dd class="text-end font-medium text-slate-700 dark:text-slate-300">{{ $plugin['release']['requires'] }}{{ $platform === 'wordpress' ? '+' : '' }}</dd>
                                                @if ($platform === 'wordpress')
                                                <dt class="text-slate-500 dark:text-slate-400">WooCommerce</dt>
                                                <dd class="text-end font-medium text-slate-700 dark:text-slate-300">{{ $plugin['release']['wc_requires'] }}+</dd>
                                                @endif
                                                <dt class="text-slate-500 dark:text-slate-400">PHP</dt>
                                                <dd class="text-end font-medium text-slate-700 dark:text-slate-300">{{ $plugin['release']['requires_php'] }}+</dd>
                                            </dl>
                                            <div class="mt-auto pt-6">
                                                <a href="{{ route('plugins.download', ['slug' => $plugin['slug'], 'version' => $plugin['release']['version']]) }}" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 py-3 text-sm font-medium text-white transition-colors hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                                                    <span aria-hidden="true">↓</span>{{ __('plugins.download', ['version' => $plugin['release']['version']]) }}
                                                </a>
                                            </div>
                                            <details class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                                                <summary class="cursor-pointer rounded text-xs font-medium text-slate-600 hover:text-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600 dark:text-slate-400 dark:hover:text-emerald-300">{{ __('plugins.changes') }}</summary>
                                                <p class="mt-3 text-xs leading-7 text-slate-600 dark:text-slate-400">{{ $plugin['release']['changelog'] }}</p>
                                            </details>
                                        @else
                                            <p class="mt-5 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ __('plugins.unpublished') }}</p>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                            <div class="mt-6 rounded-r-xl border-s-4 border-emerald-600 bg-emerald-50 px-5 py-4 dark:bg-emerald-950/35">
                                <h3 class="mb-2 text-sm font-semibold text-emerald-950 dark:text-emerald-200">{{ __('plugins.install') }}</h3>
                                <p class="text-sm leading-7 text-slate-700 dark:text-slate-300">{{ __('plugins.'.($platform === 'opencart' ? 'opencart_instructions' : 'instructions')) }}</p>
                                @if ($platform === 'opencart')
                                    <p class="mt-3 text-sm leading-7 text-slate-700 dark:text-slate-300">{{ __('plugins.opencart_upgrade') }}</p>
                                @endif
                                <p class="mt-3 text-sm leading-7 text-slate-700 dark:text-slate-300">{{ __('plugins.combined') }}</p>
                            </div>
                        @else
                            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-7 dark:border-slate-700 dark:bg-slate-900 sm:px-6">
                                <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('plugins.not_available', ['platform' => $label]) }}</h3>
                                <p class="mt-2 text-sm leading-7 text-slate-500 dark:text-slate-400">{{ __('plugins.planned_description') }}</p>
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>

            <footer class="mt-14 border-t border-slate-200 pt-7 dark:border-slate-800">
                <a href="#top" class="inline-flex items-center gap-2 rounded text-sm font-medium text-emerald-700 hover:text-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600 dark:text-emerald-400 dark:hover:text-emerald-200"><span aria-hidden="true">↑</span>{{ __('plugins.back_to_top') }}</a>
            </footer>
        </main>
    </div>
    <script>
        (() => {
            const links = document.querySelectorAll('[data-platform-link]');
            const sections = document.querySelectorAll('section[id^="platform-"]');
            const updateNavigation = () => {
                const target = Array.from(sections).some((section) => `#${section.id}` === window.location.hash)
                    ? window.location.hash : `#${sections[0].id}`;
                links.forEach((link) => {
                    if (link.getAttribute('href') === target) link.setAttribute('aria-current', 'location');
                    else link.removeAttribute('aria-current');
                });
            };
            updateNavigation();
            window.addEventListener('hashchange', updateNavigation);
            document.querySelectorAll('[data-mobile-platform-navigation] a').forEach((link) => {
                link.addEventListener('click', () => link.closest('details').removeAttribute('open'));
            });
        })();
    </script>
</body>
</html>

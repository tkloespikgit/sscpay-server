<!DOCTYPE html>
<html lang="{{ $locale === 'en' ? 'en' : 'zh-CN' }}" class="scroll-smooth motion-reduce:scroll-auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $content['page_description'] }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ $content['title'] }}</title>
    <link rel="canonical" href="{{ route('api.documentation', ['locale' => $locale]) }}">
    <link rel="alternate" hreflang="zh-CN" href="{{ route('api.documentation', ['locale' => 'zh_CN']) }}">
    <link rel="alternate" hreflang="en" href="{{ route('api.documentation', ['locale' => 'en']) }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100" data-api-documentation>
    <a href="#documentation" class="sr-only z-50 rounded-lg bg-slate-950 px-4 py-3 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4 dark:bg-white dark:text-slate-950">
        {{ $content['skip_to_content'] }}
    </a>

    <header id="top" class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm dark:border-slate-800 dark:bg-slate-950/95">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="#top" class="flex min-w-0 items-center gap-3 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-700 font-mono text-sm font-semibold text-white" aria-hidden="true">{ }</span>
                <span class="truncate text-sm font-semibold tracking-tight sm:text-base">{{ $content['title'] }}</span>
            </a>
            <nav aria-label="{{ $content['language_label'] }}" class="flex shrink-0 items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1 text-xs font-medium dark:border-slate-700 dark:bg-slate-900">
                @foreach (['zh_CN' => '中文', 'en' => 'English'] as $language => $label)
                    <a href="{{ route('api.documentation', ['locale' => $language]) }}"
                       hreflang="{{ $language === 'en' ? 'en' : 'zh-CN' }}"
                       lang="{{ $language === 'en' ? 'en' : 'zh-CN' }}"
                       data-language-link
                       @if ($language === $locale) aria-current="page" @endif
                       @class([
                           'rounded-md px-3 py-2 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600',
                           'bg-white text-slate-950 shadow-sm dark:bg-slate-700 dark:text-white' => $language === $locale,
                           'text-slate-500 hover:text-slate-950 dark:text-slate-400 dark:hover:text-white' => $language !== $locale,
                       ])>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-7 sm:px-6 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-12 lg:px-8 lg:py-10">
        <aside class="hidden min-w-0 lg:block">
            <nav aria-label="{{ $content['nav_label'] }}" class="sticky top-28 flex max-h-[calc(100vh-9rem)] flex-col gap-5 overflow-y-auto pb-8">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">{{ $content['nav_label'] }}</p>
                <ol class="flex flex-col gap-1 border-l border-slate-200 dark:border-slate-800">
                    @foreach ($content['sections'] as $id => $section)
                        <li>
                            <a href="#{{ $id }}" data-section-link="{{ $id }}" class="flex items-start gap-3 rounded-r-lg border-l-2 border-transparent px-4 py-2.5 text-sm text-slate-600 transition-colors hover:border-emerald-600 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 dark:text-slate-400 dark:hover:bg-emerald-950 dark:hover:text-emerald-300">
                                <span class="pt-0.5 font-mono text-[10px] text-slate-400" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <span>{{ $section['title'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>
        </aside>

        <main id="documentation" class="min-w-0 scroll-mt-24" tabindex="-1">
            <div class="mb-10 flex flex-col gap-5 border-b border-slate-200 pb-8 dark:border-slate-800 sm:mb-12">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ $content['title'] }}</h1>
                <p class="max-w-3xl text-base leading-8 text-slate-600 dark:text-slate-400">{{ $content['subtitle'] }}</p>
            </div>

            <details class="mb-8 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 lg:hidden" data-mobile-navigation>
                <summary class="cursor-pointer px-5 py-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-emerald-600">{{ $content['nav_label'] }}</summary>
                <nav aria-label="{{ $content['nav_label'] }}" class="border-t border-slate-100 px-3 py-3 dark:border-slate-800">
                    <ol class="grid gap-1 sm:grid-cols-2">
                        @foreach ($content['sections'] as $id => $section)
                            <li><a href="#{{ $id }}" class="block rounded-lg px-3 py-2.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-emerald-700 focus-visible:outline-2 focus-visible:outline-emerald-600 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-emerald-300">{{ $section['title'] }}</a></li>
                        @endforeach
                    </ol>
                </nav>
            </details>

            <div class="flex flex-col gap-12 sm:gap-16">
                @foreach ($content['sections'] as $id => $section)
                    <section id="{{ $id }}" aria-labelledby="{{ $id }}-title" class="min-w-0 scroll-mt-28">
                        <div class="mb-6 flex items-start gap-3">
                            <span class="mt-1 rounded-md bg-emerald-100 px-2 py-1 font-mono text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <h2 id="{{ $id }}-title" class="min-w-0 text-xl leading-9 font-semibold tracking-tight sm:text-2xl">{{ $section['title'] }}</h2>
                            <a href="#{{ $id }}" aria-label="{{ $content['anchor_label'] }}: {{ $section['title'] }}" class="mt-1 rounded px-1 font-mono text-lg text-slate-400 hover:text-emerald-700 focus-visible:outline-2 focus-visible:outline-emerald-600 dark:hover:text-emerald-300">#</a>
                        </div>
                        <div class="flex min-w-0 flex-col gap-5">
                            @foreach ($section['blocks'] as $block)
                                @switch ($block['type'])
                                    @case ('paragraph')
                                        <p class="text-sm leading-7 break-words text-slate-600 dark:text-slate-300 sm:text-base">{{ $block['text'] }}</p>
                                        @break

                                    @case ('endpoint')
                                        <div class="flex min-w-0 flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                                            <span class="rounded-md bg-emerald-100 px-2.5 py-1 font-mono text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">{{ $block['method'] }}</span>
                                            <code class="min-w-0 font-mono text-sm break-all text-slate-950 dark:text-slate-100">{{ $block['path'] }}</code>
                                            <span class="w-full text-xs leading-6 text-slate-500 dark:text-slate-400">{{ $block['direction'] }}</span>
                                        </div>
                                        @break

                                    @case ('links')
                                        <div class="flex flex-wrap gap-3 text-sm">
                                            @foreach ($block['links'] as $link)
                                                <a href="#{{ $link['anchor'] }}" class="rounded font-medium text-emerald-700 underline underline-offset-4 hover:text-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600 dark:text-emerald-400 dark:hover:text-emerald-200">{{ $link['label'] }}</a>
                                            @endforeach
                                        </div>
                                        @break

                                    @case ('table')
                                        <div class="min-w-0 overflow-x-auto rounded-xl border border-slate-200 bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 dark:border-slate-800 dark:bg-slate-900" tabindex="0" role="region" aria-label="{{ $block['caption'] ?? $section['title'] }}">
                                            <table class="w-full min-w-[36rem] border-collapse text-left text-sm">
                                                <caption class="{{ isset($block['caption']) ? 'border-b border-slate-200 px-5 py-4 text-left font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-200' : 'sr-only' }}">{{ $block['caption'] ?? $section['title'] }}</caption>
                                                <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/70 dark:text-slate-400">
                                                    <tr>
                                                        @foreach ($block['headers'] as $heading)
                                                            <th scope="col" class="px-5 py-3.5 font-semibold">{{ $heading }}</th>
                                                        @endforeach
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                                    @foreach ($block['rows'] as $row)
                                                        <tr class="align-top">
                                                            @foreach ($row as $cell)
                                                                <td @class([
                                                                    'px-5 py-3.5 leading-7',
                                                                    'font-mono text-xs whitespace-nowrap text-slate-900 dark:text-slate-100' => $loop->first,
                                                                    'text-slate-600 dark:text-slate-300' => ! $loop->first,
                                                                ])>{{ $cell }}</td>
                                                            @endforeach
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        @break

                                    @case ('code')
                                        <figure class="min-w-0 overflow-hidden rounded-xl border border-slate-800 bg-slate-950 text-slate-200" data-code-block>
                                            <figcaption class="flex items-center justify-between gap-4 border-b border-slate-800 bg-slate-900 px-4 py-3 sm:px-5">
                                                <span class="min-w-0 text-xs leading-6 text-slate-300">{{ $block['label'] }}</span>
                                                <button type="button" data-copy-code data-copy-label="{{ $content['copy_code'] }}" data-copied-label="{{ $content['copied'] }}" data-failed-label="{{ $content['copy_failed'] }}" class="shrink-0 rounded-md border border-slate-700 px-2.5 py-1.5 text-xs font-medium text-slate-300 transition-colors hover:border-slate-500 hover:bg-slate-800 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400">{{ $content['copy_code'] }}</button>
                                            </figcaption>
                                            <pre class="overflow-x-auto px-4 py-5 font-mono text-xs leading-7 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-emerald-400 sm:px-5 sm:text-sm" tabindex="0" aria-label="{{ $block['label'] }}"><code data-code-content data-language="{{ $block['language'] }}">{{ $block['code'] }}</code></pre>
                                        </figure>
                                        @break

                                    @case ('callout')
                                        <aside @class([
                                            'rounded-r-xl border-l-4 px-5 py-4',
                                            'border-amber-500 bg-amber-50 dark:bg-amber-950/35' => $block['tone'] === 'warning',
                                            'border-emerald-600 bg-emerald-50 dark:bg-emerald-950/35' => $block['tone'] !== 'warning',
                                        ])>
                                            <h3 @class([
                                                'mb-2 text-sm font-semibold',
                                                'text-amber-950 dark:text-amber-200' => $block['tone'] === 'warning',
                                                'text-emerald-950 dark:text-emerald-200' => $block['tone'] !== 'warning',
                                            ])>{{ $block['title'] }}</h3>
                                            <p class="text-sm leading-7 break-words text-slate-700 dark:text-slate-300">{{ $block['text'] }}</p>
                                        </aside>
                                        @break
                                @endswitch
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <footer class="mt-14 border-t border-slate-200 pt-7 dark:border-slate-800">
                <a href="#top" class="inline-flex items-center gap-2 rounded text-sm font-medium text-emerald-700 hover:text-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-600 dark:text-emerald-400 dark:hover:text-emerald-200">
                    <span aria-hidden="true">↑</span> {{ $content['back_to_top'] }}
                </a>
            </footer>
        </main>
    </div>
    <span class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-copy-status></span>

    <script>
        (() => {
            const root = document.querySelector('[data-api-documentation]');
            if (!root) return;

            const languageLinks = root.querySelectorAll('[data-language-link]');
            const updateLanguageLinks = () => {
                languageLinks.forEach((link) => {
                    const destination = new URL(link.href);
                    destination.hash = window.location.hash;
                    link.href = destination.href;
                });
            };
            updateLanguageLinks();
            window.addEventListener('hashchange', updateLanguageLinks);

            root.querySelectorAll('[data-mobile-navigation] a').forEach((link) => {
                link.addEventListener('click', () => link.closest('details').removeAttribute('open'));
            });

            const status = root.querySelector('[data-copy-status]');
            const copyText = async (text) => {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return;
                }
                const field = document.createElement('textarea');
                const previousFocus = document.activeElement;
                field.value = text;
                field.readOnly = true;
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                try {
                    if (!document.execCommand('copy')) throw new Error('Clipboard unavailable');
                } finally {
                    field.remove();
                    previousFocus?.focus();
                }
            };

            root.querySelectorAll('[data-copy-code]').forEach((button) => {
                let resetTimer;
                button.addEventListener('click', async () => {
                    clearTimeout(resetTimer);
                    button.disabled = true;
                    try {
                        await copyText(button.closest('[data-code-block]').querySelector('[data-code-content]').textContent);
                        button.textContent = button.dataset.copiedLabel;
                    } catch {
                        button.textContent = button.dataset.failedLabel;
                    } finally {
                        status.textContent = button.textContent;
                        button.disabled = false;
                        resetTimer = window.setTimeout(() => button.textContent = button.dataset.copyLabel, 2500);
                    }
                });
            });
        })();
    </script>
</body>
</html>

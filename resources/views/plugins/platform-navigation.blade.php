<ul class="flex flex-col gap-1 border-s border-slate-200 dark:border-slate-800">
    @foreach ($platforms as $platform => $label)
        <li>
            <a href="#platform-{{ $platform }}"
               data-platform-link
               @if ($loop->first) aria-current="location" @endif
               class="flex items-center gap-3 rounded-e-lg border-s-2 border-transparent px-4 py-3 text-sm text-slate-600 transition-colors hover:border-emerald-600 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 aria-[current=location]:border-emerald-600 aria-[current=location]:bg-emerald-50 aria-[current=location]:font-medium aria-[current=location]:text-emerald-800 dark:text-slate-400 dark:hover:bg-emerald-950 dark:hover:text-emerald-300 dark:aria-[current=location]:bg-emerald-950 dark:aria-[current=location]:text-emerald-300">
                <span class="font-mono text-[10px] text-slate-400" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <span>{{ $label }}</span>
                @if ($pluginsByPlatform->get($platform, collect())->isEmpty())
                    <span class="ms-auto shrink-0 text-[10px] text-slate-400 dark:text-slate-500">{{ __('plugins.planned') }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>

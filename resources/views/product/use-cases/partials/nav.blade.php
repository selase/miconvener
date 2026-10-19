{{-- The use-case sub-menu: all seven, the current one marked; scrolls sideways on phones. --}}
<nav aria-label="Use cases" class="border-b border-slate-200 bg-white" data-use-case-nav>
    <div class="mx-auto flex max-w-7xl items-center gap-6 px-6">
        <a href="{{ route('use-cases.index') }}" class="mono hidden shrink-0 border-r border-slate-200 py-3.5 pr-6 text-[11px] uppercase tracking-[0.14em] text-slate-400 hover:text-slate-700 md:block">Use cases</a>
        <div class="-mb-px flex min-w-0 flex-1 gap-6 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($cases as $item)
                @php($isCurrent = ($current ?? null) === $item['slug'])
                <a href="{{ route('use-cases.show', $item['slug']) }}"
                   @if ($isCurrent) aria-current="page" @endif
                   class="shrink-0 whitespace-nowrap border-b-2 py-3.5 text-sm transition {{ $isCurrent ? 'border-[var(--field)] font-medium text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-900' }}">
                    {{ $item['eyebrow'] }}
                </a>
            @endforeach
        </div>
        <a href="{{ route('use-cases.index') }}" class="hidden shrink-0 py-3.5 text-sm text-slate-500 hover:text-slate-900 lg:block">All use cases &rarr;</a>
    </div>
</nav>

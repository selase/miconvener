{{-- A row of headline figures inside a hero panel. $stats: list of [label, value, highlight?]. --}}
<div class="grid border-b border-white/10" style="grid-template-columns: repeat({{ count($stats) }}, minmax(0, 1fr))">
    @foreach ($stats as $stat)
        <div class="px-5 py-4 {{ $loop->last ? '' : 'border-r border-white/10' }}">
            <div class="mono text-[10px] uppercase tracking-wider text-slate-500">{{ $stat[0] }}</div>
            <div class="mt-1 text-2xl font-semibold {{ ($stat[2] ?? false) ? 'text-[#6f9bff]' : 'text-white' }}">{{ $stat[1] }}</div>
        </div>
    @endforeach
</div>

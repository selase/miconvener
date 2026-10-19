{{-- Labelled progress bars inside a hero panel. $rows: list of [label, value, of, text]. --}}
<div class="px-5 py-3">
    <div class="mono flex justify-between pb-1 text-[10px] uppercase tracking-wider text-slate-500"><span>{{ $left }}</span><span>{{ $right }}</span></div>
    @foreach ($rows as $row)
        <div class="grid grid-cols-[6.5rem_1fr_auto] items-center gap-3 border-t border-white/5 py-2 text-[13px] text-slate-200 sm:grid-cols-[8rem_1fr_auto]">
            <span class="truncate">{{ $row[0] }}</span>
            <span class="h-1 rounded-full bg-white/10"><span class="block h-1 rounded-full bg-[#4d86ff]" style="width: {{ round($row[1] / $row[2] * 100) }}%"></span></span>
            <span class="mono text-xs text-slate-400">{{ $row[3] }}</span>
        </div>
    @endforeach
</div>

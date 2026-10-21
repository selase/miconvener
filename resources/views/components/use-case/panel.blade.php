@props(['title', 'subtitle', 'badge' => null])
{{-- A product panel on the black hero band: what the console shows for this kind of event. Sample figures. --}}
<div class="overflow-hidden rounded-lg border border-white/10 bg-[#0f1216] text-left shadow-2xl shadow-black/40" data-use-case-panel>
    <div class="flex items-start justify-between gap-4 border-b border-white/10 px-5 py-4">
        <div>
            <div class="text-sm font-semibold text-white">{{ $title }}</div>
            <div class="mt-0.5 text-xs text-slate-400">{{ $subtitle }}</div>
        </div>
        @if ($badge)
            <span class="mono inline-flex shrink-0 items-center gap-1.5 rounded border border-[#155dfc]/50 px-2 py-0.5 text-[10px] uppercase tracking-wider text-[#8fb2ff]">
                <span class="h-1.5 w-1.5 rounded-full bg-[#4d86ff]"></span>{{ $badge }}
            </span>
        @endif
    </div>
    {{ $slot }}
</div>

<x-use-case.panel title="At the door" subtitle="Class reunion dinner · Saturday 18:00" badge="Live">
    @include('product.use-cases.panels._stats', ['stats' => [['Tickets', '214'], ['Arrived', '188', true], ['Helpers', '3']]])
    <div class="px-5 py-3">
        <div class="mono grid grid-cols-[1fr_auto_auto] gap-4 pb-1 text-[10px] uppercase tracking-wider text-slate-500"><span>Guest</span><span>Ticket</span><span class="w-12 text-right">Time</span></div>
        @foreach ([['Guest 188', 'Couple', '19:31'], ['Guest 187', 'Regular', '19:30'], ['Guest 186', 'Early bird', '19:28'], ['Guest 185', 'Regular', '19:28']] as [$guest, $ticket, $time])
            <div class="grid grid-cols-[1fr_auto_auto] items-center gap-4 border-t border-white/5 py-2 text-[13px] text-slate-200">
                <span>{{ $guest }}</span><span class="text-xs text-slate-400">{{ $ticket }}</span><span class="mono w-12 text-right text-xs text-slate-400">{{ $time }}</span>
            </div>
        @endforeach
    </div>
    @include('product.use-cases.panels._footer', ['text' => 'Pass scanned by a helper link', 'time' => '19:31'])
</x-use-case.panel>

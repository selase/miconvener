<x-use-case.panel title="Sunday service" subtitle="Repeats every Sunday · 09:00" badge="Weekly">
    @include('product.use-cases.panels._stats', ['stats' => [['Members', '640'], ['Signed up', '412', true], ['Groups', '9']]])
    <div class="px-5 py-3">
        <div class="mono grid grid-cols-[1fr_auto] gap-4 pb-1 text-[10px] uppercase tracking-wider text-slate-500"><span>Upcoming</span><span>Reminder</span></div>
        @foreach ([['This Sunday', 'Sent Saturday 18:00'], ['Next Sunday', 'Saturday 18:00'], ['In two weeks', 'Saturday 18:00'], ['In three weeks', 'Saturday 18:00']] as [$date, $reminder])
            <div class="grid grid-cols-[1fr_auto] items-center gap-4 border-t border-white/5 py-2 text-[13px] text-slate-200">
                <span>{{ $date }}</span>
                <span class="text-xs {{ $loop->first ? 'text-[#6f9bff]' : 'text-slate-400' }}">{{ $reminder }}</span>
            </div>
        @endforeach
    </div>
    @include('product.use-cases.panels._footer', ['text' => 'Offering received · Receipt sent', 'time' => '10:32'])
</x-use-case.panel>

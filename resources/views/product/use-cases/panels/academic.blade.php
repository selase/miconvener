<x-use-case.panel title="Abstract review" subtitle="Annual Research Conference · Call closed" badge="Reviewing">
    @include('product.use-cases.panels._stats', ['stats' => [['Submitted', '214'], ['Reviewed', '176', true], ['Accepted', '98']]])
    <div class="px-5 py-3">
        <div class="mono grid grid-cols-[1fr_auto_auto] gap-4 pb-1 text-[10px] uppercase tracking-wider text-slate-500"><span>Abstract</span><span>Score</span><span class="w-20 text-right">Decision</span></div>
        @foreach ([
            ['Malaria vector control in coastal districts', '4.6', 'Accepted'],
            ['Soil carbon under cocoa agroforestry', '4.1', 'Accepted'],
            ['Mobile money and household savings', '—', 'In review'],
            ['Teacher retention in rural schools', '3.9', 'Accepted'],
            ['Urban flooding and drainage design', '2.7', 'Declined'],
        ] as [$abstract, $score, $decision])
            <div class="grid grid-cols-[1fr_auto_auto] items-center gap-4 border-t border-white/5 py-2 text-[13px] text-slate-200">
                <span class="truncate">{{ $abstract }}</span>
                <span class="mono text-xs text-slate-400">{{ $score }}</span>
                <span class="w-20 text-right text-xs {{ $decision === 'Accepted' ? 'text-[#6f9bff]' : 'text-slate-500' }}">{{ $decision }}</span>
            </div>
        @endforeach
    </div>
    @include('product.use-cases.panels._footer', ['text' => 'Decision emailed to the author', 'time' => '14:20'])
</x-use-case.panel>

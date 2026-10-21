<x-use-case.panel title="Delegate approvals" subtitle="National Congress · Registration open" badge="Open">
    @include('product.use-cases.panels._stats', ['stats' => [['Applied', '436'], ['Approved', '420', true], ['Branches', '16']]])
    <div class="px-5 py-3">
        <div class="mono grid grid-cols-[1fr_auto_auto] gap-4 pb-1 text-[10px] uppercase tracking-wider text-slate-500"><span>Delegate</span><span>Branch</span><span class="w-20 text-right">Status</span></div>
        @foreach ([
            ['Delegate 0412', 'Ashanti', 'Paid'],
            ['Delegate 0413', 'Volta', 'Approved'],
            ['Delegate 0414', 'Northern', 'Paid'],
            ['Delegate 0415', 'Central', 'Awaiting'],
            ['Delegate 0416', 'Greater Accra', 'Paid'],
        ] as [$name, $branch, $status])
            <div class="grid grid-cols-[1fr_auto_auto] items-center gap-4 border-t border-white/5 py-2 text-[13px] text-slate-200">
                <span>{{ $name }}</span>
                <span class="text-xs text-slate-400">{{ $branch }}</span>
                <span class="w-20 text-right text-xs {{ $status === 'Awaiting' ? 'text-slate-500' : 'text-[#6f9bff]' }}">{{ $status }}</span>
            </div>
        @endforeach
    </div>
    @include('product.use-cases.panels._footer', ['text' => 'Fee received · Mobile money', 'time' => '11:47'])
</x-use-case.panel>

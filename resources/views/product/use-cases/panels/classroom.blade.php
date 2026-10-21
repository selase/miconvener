<x-use-case.panel title="Which topic should we cover next?" subtitle="Data Skills Workshop · Poll 3 of 4" badge="Live">
    @include('product.use-cases.panels._bars', ['left' => 'Answer', 'right' => 'Share', 'rows' => [
        ['Data cleaning', 42, 100, '42%'], ['Visualisation', 31, 100, '31%'], ['Statistics', 18, 100, '18%'], ['Ethics', 9, 100, '9%'],
    ]])
    <div class="border-t border-white/10 px-5 py-3">
        <div class="mono pb-1 text-[10px] uppercase tracking-wider text-slate-500">Questions from the room</div>
        @foreach ([['Can we get the dataset after class?', 18], ['How do pivot tables handle blanks?', 12]] as [$question, $votes])
            <div class="flex items-center justify-between gap-4 border-t border-white/5 py-2 text-[13px] text-slate-200">
                <span class="truncate">{{ $question }}</span><span class="mono text-xs text-slate-400">▲ {{ $votes }}</span>
            </div>
        @endforeach
    </div>
    @include('product.use-cases.panels._footer', ['text' => '64 answers from the room', 'time' => '10:15'])
</x-use-case.panel>

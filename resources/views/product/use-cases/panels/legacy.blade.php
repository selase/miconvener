<x-use-case.panel title="Tribute & condolence wall" subtitle="Celebration of life · Saturday" badge="Moderated">
    @include('product.use-cases.panels._stats', ['stats' => [['Tributes', '128'], ['Coming', '340', true], ['Gifts', '238']]])
    <div class="space-y-3 px-5 py-4">
        @foreach ([
            ['She taught half our street to read, and never once asked for thanks.', 'A neighbour'],
            ['Thank you for every Sunday lunch and every kind word. We will miss you.', 'The youth fellowship'],
            ['A teacher, a mother to many, and a friend to all of us.', 'Former colleagues'],
        ] as [$tribute, $from])
            <figure class="rounded border border-white/5 bg-white/[0.03] px-4 py-3">
                <blockquote class="text-[13px] leading-5 text-slate-200">{{ $tribute }}</blockquote>
                <figcaption class="mt-1.5 text-xs text-slate-500">{{ $from }}</figcaption>
            </figure>
        @endforeach
    </div>
    @include('product.use-cases.panels._footer', ['text' => 'Tribute approved by the family', 'time' => '16:05'])
</x-use-case.panel>

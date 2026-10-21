<x-use-case.panel title="Registration desk" subtitle="Regional Summit · Day 2 of 5" badge="Live">
    @include('product.use-cases.panels._stats', ['stats' => [['Registered', '1,240'], ['Checked in', '986', true], ['Delegations', '48']]])
    @include('product.use-cases.panels._bars', ['left' => 'Delegation', 'right' => 'Arrived', 'rows' => [
        ['Ghana', 42, 46, '42 / 46'], ['Kenya', 38, 40, '38 / 40'], ['Germany', 31, 36, '31 / 36'],
        ['Brazil', 27, 34, '27 / 34'], ['Japan', 22, 30, '22 / 30'], ['Canada', 18, 28, '18 / 28'],
    ]])
    @include('product.use-cases.panels._footer', ['text' => 'Pass scanned · Delegate · Plenary Hall A', 'time' => '09:02'])
</x-use-case.panel>

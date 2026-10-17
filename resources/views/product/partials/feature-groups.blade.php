@once
    @push('styles')
    <style>
        .cap { display: grid; gap: 1px; background: var(--rule); border: 1px solid var(--rule); border-radius: 12px; overflow: hidden; grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 900px) { .cap { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 580px) { .cap { grid-template-columns: 1fr; } }
        .cap > div { background: var(--surface); padding: 1.45rem 1.35rem; }
        .cap h3, .cap h4 { font-weight: 500; font-size: 1rem; }
        .cap p { color: var(--ink-2); font-size: .92rem; margin-top: .3rem; }
        .feature-groups { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:36px 32px; }
        .feature-group { min-width:0; }
        .feature-group > h3 { font-size:18px; }
        .feature-group .cap { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .feature-group .cap > div { padding:20px; }
        .feature-group .cap h4 { font-size:14px; font-weight:500; line-height:1.5; }
        .feature-group .cap p { font-size:13px; line-height:1.7; }
        .feature-group .feature-wide { grid-column:1 / -1; }
        @media (max-width: 900px) { .feature-groups { grid-template-columns:1fr; } }
        @media (max-width: 580px) { .feature-group .cap { grid-template-columns:1fr; } }
    </style>
    @endpush
@endonce
<div class="feature-groups">
    @foreach (collect(config('product-page.capabilities.items'))->groupBy('group') as $group => $items)
        <div class="feature-group">
            <h3 class="display">{{ $group }}</h3>
            <div class="cap mt-4">
                @foreach ($items as $item)
                    <div class="{{ $loop->last && $items->count() % 2 !== 0 ? 'feature-wide' : '' }}">
                        <h4>{{ $item['title'] }}</h4>
                        <p>{{ $item['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>

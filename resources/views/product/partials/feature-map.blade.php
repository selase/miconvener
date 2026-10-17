{{-- Homepage feature map: the centre (logo) connects to six areas, each to three key features. Connectors are drawn by the script below from the laid-out positions; on narrow screens the map stacks and the connectors are hidden. --}}
@once
    @push('styles')
    <style>
        .feature-map { --fm-line:#c9ccd4; --fm-pulse:#3a6fe0; --fm-face:#ffffff; --fm-top:#eef1f7; --fm-hi:#c9d8fb; --fm-stroke:#1b2140; --fm-dim:#a3a8b6;
            position:relative; margin-top:64px; display:grid; grid-template-columns: 190px 230px minmax(200px, 1fr) 230px 190px; column-gap:52px; row-gap:44px; align-items:center; }
        .feature-map-center { grid-column:3; grid-row:1 / span 3; justify-self:center; position:relative; z-index:2; padding:10px 14px; }
        .feature-map-center img { display:block; width:190px; height:auto; }
        .feature-map-area { position:relative; z-index:2; display:flex; align-items:center; gap:10px; font-weight:600; font-size:15px; line-height:1.25; color:var(--ink); padding:4px 0; }
        .feature-map-area[data-side="left"] { flex-direction:row-reverse; text-align:right; justify-self:end; }
        .feature-map-area[data-side="right"] { justify-self:start; }
        .feature-map-area .feature-map-label { max-width:170px; }
        .feature-map-icon { flex:none; width:34px; height:30px; }
        .feature-map-icon polygon { stroke-width:1.6; stroke-linejoin:round; vector-effect:non-scaling-stroke; }
        .feature-map-icon line { stroke-width:1; vector-effect:non-scaling-stroke; }
        .feature-map-leaves { position:relative; z-index:2; display:grid; gap:14px; margin:0; padding:0; list-style:none; }
        .feature-map-leaves li { font-size:14px; color:var(--ink-2); line-height:1.25; }
        .feature-map-leaves[data-side="left"] li { text-align:right; }
        .feature-map-lines { position:absolute; inset:0; width:100%; height:100%; z-index:1; overflow:visible; pointer-events:none; }
        .feature-map-lines path { fill:none; stroke:var(--fm-line); stroke-width:1.3; }
        .feature-map-lines path.pulse { stroke:var(--fm-pulse); stroke-width:2; stroke-linecap:round; stroke-dasharray:12 600; animation:feature-map-run 5s linear infinite; }
        @keyframes feature-map-run { from { stroke-dashoffset:612; } to { stroke-dashoffset:0; } }
        @media (prefers-reduced-motion: reduce) { .feature-map-lines path.pulse { display:none; } }
        @media (max-width: 1080px) {
            .feature-map { grid-template-columns:1fr; row-gap:0; max-width:420px; margin-left:auto; margin-right:auto; }
            .feature-map-center { grid-column:auto; grid-row:auto !important; margin:0 auto 32px; }
            .feature-map-area, .feature-map-area[data-side="left"] { grid-column:auto !important; grid-row:auto !important; flex-direction:row; text-align:left; justify-self:start; }
            .feature-map-leaves, .feature-map-leaves[data-side="left"] { grid-column:auto !important; grid-row:auto !important; margin:10px 0 28px 16px; padding-left:28px; border-left:1px solid var(--fm-line); }
            .feature-map-leaves[data-side="left"] li { text-align:left; }
            .feature-map-lines { display:none; }
        }
    </style>
    @endpush
    @push('scripts')
    <script data-feature-map-script>
    (() => {
        const map = document.querySelector('[data-feature-map]');
        if (!map) return;
        const svg = map.querySelector('.feature-map-lines');
        const curve = (x1, y1, x2, y2) => { const mx = (x1 + x2) / 2; return `M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}`; };
        const draw = () => {
            if (getComputedStyle(svg).display === 'none') { svg.innerHTML = ''; return; }
            const box = map.getBoundingClientRect();
            const rel = (r) => ({ l: r.left - box.left, r: r.right - box.left, m: r.top - box.top + r.height / 2 });
            const centre = rel(map.querySelector('.feature-map-center img').getBoundingClientRect());
            let html = '';
            map.querySelectorAll('.feature-map-area').forEach((area, index) => {
                const left = area.dataset.side === 'left', a = rel(area.getBoundingClientRect());
                const trunk = left ? curve(a.r + 8, a.m, centre.l - 10, centre.m) : curve(centre.r + 10, centre.m, a.l - 8, a.m);
                html += `<path d="${trunk}"/><path class="pulse" d="${trunk}" style="animation-delay:${(index * 0.8).toFixed(1)}s"/>`;
                const range = document.createRange();
                range.selectNodeContents(area.querySelector('.feature-map-label'));
                const text = rel(range.getBoundingClientRect());
                const start = left ? text.l - 8 : text.r + 8;
                map.querySelectorAll(`[data-leaves="${area.dataset.area}"] li`).forEach((leaf) => {
                    const l = rel(leaf.getBoundingClientRect());
                    html += `<path d="${curve(start, a.m, left ? l.r + 8 : l.l - 8, l.m)}"/>`;
                });
            });
            svg.innerHTML = html;
        };
        window.addEventListener('load', draw);
        window.addEventListener('resize', draw);
        if (document.fonts) document.fonts.ready.then(draw);
    })();
    </script>
    @endpush
@endonce
<div class="feature-map" data-feature-map>
    <svg class="feature-map-lines" aria-hidden="true" focusable="false"></svg>
    <div class="feature-map-center">
        <img src="{{ asset('assets/img/brand/miconvener.png') }}" srcset="{{ asset('assets/img/brand/miconvener.png') }} 1x, {{ asset('assets/img/brand/miconvener@2x.png') }} 2x" alt="{{ config('product-page.brand.name') }}" width="190" height="35" loading="lazy">
    </div>
    @php($rows = ['left' => 1, 'right' => 1])
    @foreach (config('product-page.feature_map.areas') as $area)
        @php($row = $rows[$area['side']]++)
        @php($leftSide = $area['side'] === 'left')
        <h3 class="feature-map-area" data-side="{{ $area['side'] }}" data-area="{{ $area['icon'] }}" style="grid-column: {{ $leftSide ? 2 : 4 }}; grid-row: {{ $row }}">
            @include('product.partials.feature-map-icons.'.$area['icon'])
            <span class="feature-map-label">{{ $area['group'] }}</span>
        </h3>
        <ul class="feature-map-leaves" data-side="{{ $area['side'] }}" data-leaves="{{ $area['icon'] }}" style="grid-column: {{ $leftSide ? 1 : 5 }}; grid-row: {{ $row }}">
            @foreach ($area['features'] as $feature)
                <li>{{ $feature }}</li>
            @endforeach
        </ul>
    @endforeach
</div>

<!doctype html>
<html><head><meta charset="utf-8"><style>
@page { margin: 0; }
* { box-sizing: border-box; }
body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; }
.sheet { page-break-after: always; padding: {{ $margin }}mm; font-size: 0; }
.sheet:last-child { page-break-after: avoid; }
.badge { position: relative; display: inline-block; width: {{ $template->width_mm }}mm; height: {{ $template->height_mm }}mm; margin: 0 {{ $gap }}mm {{ $gap }}mm 0; overflow: hidden; vertical-align: top; background: #fff; border: {{ $cropMarks ? '0.15mm dashed #94a3b8' : 'none' }}; }
.badge:nth-child({{ $columns }}n) { margin-right: 0; }
.background { position: absolute; inset: 0; width: 100%; height: 100%; }
.element { position: absolute; overflow: hidden; line-height: 1.1; }
.qr img { width: 100%; height: 100%; object-fit: contain; }
</style></head><body>
@foreach ($pages as $page)
<section class="sheet">
@foreach ($page as $badge)
<div class="badge" style="background-color: {{ $badge['tier_style']['background_color'] ?? '#FFFFFF' }}; color: {{ $badge['tier_style']['text_color'] ?? '#111827' }}">
    @if ($backgroundDataUri)<img class="background" src="{{ $backgroundDataUri }}" alt="">@endif
    @foreach ($layout as $key => $item)
        @continue(($item['visible'] ?? true) === false)
        @if ($key === 'qr')
            @if ($badge['qr'])<div class="element qr" style="left:{{ $item['x'] * 100 }}%;top:{{ $item['y'] * 100 }}%;width:{{ $item['width'] * 100 }}%;height:{{ $item['height'] * 100 }}%"><img src="{{ $badge['qr'] }}" alt="QR"></div>@endif
        @elseif ($key === 'tenant_logo')
            @if ($tenantLogoDataUri)<div class="element qr" style="left:{{ $item['x'] * 100 }}%;top:{{ $item['y'] * 100 }}%;width:{{ $item['width'] * 100 }}%;height:{{ $item['height'] * 100 }}%"><img src="{{ $tenantLogoDataUri }}" alt="Tenant logo"></div>@endif
        @else
            <div class="element" style="left:{{ $item['x'] * 100 }}%;top:{{ $item['y'] * 100 }}%;width:{{ $item['width'] * 100 }}%;height:{{ $item['height'] * 100 }}%;text-align:{{ $item['align'] }};font-family:'{{ $item['font_family'] }}';font-size:{{ $item['font_size'] }}pt;font-weight:{{ $item['font_weight'] }};color:{{ $badge['tier_style']['text_color'] ?? $item['color'] }}">{{ $badge['values'][$key] ?? '' }}</div>
        @endif
    @endforeach
</div>
@endforeach
</section>
@endforeach
</body></html>

<!doctype html>
<html><head><meta charset="utf-8"><style>
@page { margin: 0; }
* { box-sizing: border-box; }
body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; }
.sheet { position: relative; page-break-after: always; width: {{ $paperWidth }}mm; height: {{ $paperHeight - 0.05 }}mm; font-size: 0; }
.sheet:last-child { page-break-after: avoid; }
.badge-slot { position: absolute; width: {{ $template->width_mm }}mm; height: {{ $template->height_mm }}mm; }
.badge { position: relative; width: {{ $template->width_mm }}mm; height: {{ $template->height_mm }}mm; overflow: hidden; background: #fff; }
.crop-mark { position: absolute; background: #111827; }
.background { position: absolute; inset: 0; width: 100%; height: 100%; }
.element { position: absolute; overflow: hidden; line-height: 1.1; }
.logo img { width: auto; height: auto; max-width: 100%; max-height: 100%; }
</style></head><body>
@foreach ($pages as $page)
<section class="sheet">
@foreach ($page as $badge)
<div class="badge-slot" style="left:{{ $margin + ($loop->index % $columns) * ($template->width_mm + $gap) }}mm;top:{{ $margin + intdiv($loop->index, $columns) * ($template->height_mm + $gap) }}mm">
<div class="badge" style="background-color: {{ $badge['tier_style']['background_color'] ?? '#FFFFFF' }}; color: {{ $badge['tier_style']['text_color'] ?? '#111827' }}">
    @if ($backgroundDataUri)<img class="background" src="{{ $backgroundDataUri }}" alt="">@endif
    @foreach ($badge['layout'] as $key => $item)
        @continue(($item['visible'] ?? true) === false)
        @if ($key === 'qr')
            @php($qrSize = min($item['width'] * $template->width_mm, $item['height'] * $template->height_mm))
            @if ($badge['qr'])<div class="element qr" style="left:{{ $item['x'] * 100 }}%;top:{{ $item['y'] * 100 }}%;width:{{ $item['width'] * 100 }}%;height:{{ $item['height'] * 100 }}%;text-align:{{ $item['align'] }}"><img src="{{ $badge['qr'] }}" style="width:{{ $qrSize }}mm;height:{{ $qrSize }}mm" alt="QR"></div>@endif
        @elseif ($key === 'tenant_logo')
            @if ($tenantLogoDataUri)<div class="element logo" style="left:{{ $item['x'] * 100 }}%;top:{{ $item['y'] * 100 }}%;width:{{ $item['width'] * 100 }}%;height:{{ $item['height'] * 100 }}%;text-align:{{ $item['align'] }}"><img src="{{ $tenantLogoDataUri }}" alt="Tenant logo"></div>@endif
        @else
            <div class="element" style="left:{{ $item['x'] * 100 }}%;top:{{ $item['y'] * 100 }}%;width:{{ $item['width'] * 100 }}%;height:{{ $item['height'] * 100 }}%;text-align:{{ $item['align'] }};font-family:'{{ $item['font_family'] }}';font-size:{{ $item['font_size'] }}pt;font-weight:{{ $item['font_weight'] >= 600 ? 700 : 400 }};color:{{ $badge['tier_style']['text_color'] ?? $item['color'] }}">{{ $badge['values'][$key] ?? '' }}</div>
        @endif
    @endforeach
</div>
@if ($cropMarks)
    @foreach (['tl', 'tr', 'bl', 'br'] as $corner)
        @php($right = str_contains($corner, 'r'))
        @php($bottom = str_contains($corner, 'b'))
        <div class="crop-mark" style="width:1mm;height:0.15mm;left:{{ $right ? $template->width_mm + 0.4 : -1.4 }}mm;top:{{ $bottom ? $template->height_mm : 0 }}mm"></div>
        <div class="crop-mark" style="width:0.15mm;height:1mm;left:{{ $right ? $template->width_mm : 0 }}mm;top:{{ $bottom ? $template->height_mm + 0.4 : -1.4 }}mm"></div>
    @endforeach
@endif
</div>
@endforeach
</section>
@endforeach
</body></html>

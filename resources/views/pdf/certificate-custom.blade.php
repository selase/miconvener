<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $certificate->recipient_name }} - {{ $template?->title ?? 'Certificate' }}</title>
    <style>
        @page { size: A4 landscape; margin: 0; }
        html, body { width: 100%; height: 100%; margin: 0; padding: 0; }
        body { font-family: Helvetica, Arial, sans-serif; }
        .certificate-page {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #fff url('{{ $backgroundDataUri }}') center center / 100% 100% no-repeat;
        }
        .artifact-element {
            position: absolute;
            box-sizing: border-box;
            overflow: hidden;
            line-height: 1.15;
        }
        .artifact-element img { max-width: 100%; max-height: 100%; }
        .signature-image { display: block; width: auto; height: auto; max-width: 100%; max-height: 100%; }
    </style>
</head>
<body>
@if(!empty($previewWarning))
    <div style="position: fixed; top: 0; left: 0; right: 0; padding: 2mm; background: #fef3c7; color: #92400e; font: 9pt 'DejaVu Sans'; z-index: 10;">{{ $previewWarning }}</div>
@endif
<div class="certificate-page">
    @foreach($layout as $element => $settings)
        @php
            $isVisible = $settings['visible']
                && ($element !== 'qr' || !empty($qrDataUri))
                && ($element !== 'signature' || !empty($signatureDataUri))
                && ($element !== 'cpd_hours' || (($template?->show_cpd_hours ?? false) && $certificate->cpd_hours > 0));
        @endphp
        @if($isVisible)
            <div
                class="artifact-element artifact-{{ $element }}"
                style="left: {{ $settings['x'] * 100 }}%; top: {{ $settings['y'] * 100 }}%; width: {{ $settings['width'] * 100 }}%; height: {{ $settings['height'] * 100 }}%; text-align: {{ $settings['align'] }}; font-family: '{{ $settings['font_family'] }}'; font-size: {{ $settings['font_size'] }}pt; font-weight: {{ $settings['font_weight'] >= 600 ? 700 : 400 }}; color: {{ $settings['color'] }};"
            >
                @if($element === 'qr')
                    @php($qrSize = min($settings['width'] * 297, $settings['height'] * 210))
                    <img src="{{ $qrDataUri }}" style="width:{{ $qrSize }}mm;height:{{ $qrSize }}mm" alt="Verification QR">
                @elseif($element === 'signature')
                    <img src="{{ $signatureDataUri }}" class="signature-image" alt="Issuer signature">
                @else
                    {{ $elementValues[$element] ?? '' }}
                @endif
            </div>
        @endif
    @endforeach
</div>
</body>
</html>

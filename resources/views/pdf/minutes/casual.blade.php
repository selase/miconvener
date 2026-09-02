<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.7; padding: 25px; color: #444; }
        .header { padding: 20px; margin-bottom: 20px; background: linear-gradient(135deg, {{ $primaryColor }}15, {{ $primaryColor }}08); border-left: 4px solid {{ $primaryColor }}; border-radius: 0 8px 8px 0; }
        .header .org { font-size: 11px; color: {{ $primaryColor }}; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .header h1 { font-size: 20px; margin: 8px 0 5px 0; color: #333; }
        .header .meta { font-size: 11px; color: #888; }
        @if($logoUrl)
        .logo { max-height: 35px; margin-bottom: 8px; }
        @endif
        h1 { font-size: 18px; color: {{ $primaryColor }}; margin-top: 25px; }
        h2 { font-size: 15px; margin-top: 20px; color: #555; padding-bottom: 5px; border-bottom: 1px dashed #ddd; }
        h3 { font-size: 13px; margin-top: 15px; color: #666; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; border-radius: 4px; overflow: hidden; }
        th, td { padding: 10px 12px; text-align: left; }
        th { background-color: {{ $primaryColor }}; color: #fff; }
        td { border-bottom: 1px solid #eee; }
        tr:nth-child(even) td { background-color: #fafafa; }
        ul { list-style-type: disc; padding-left: 20px; }
        ol { padding-left: 20px; }
        li { margin-bottom: 5px; }
        .footer { margin-top: 30px; padding-top: 10px; text-align: center; font-size: 10px; color: #bbb; }
    </style>
</head>
<body>
    <div class="header">
        @if($logoUrl)
            <img src="{{ $logoUrl }}" class="logo" alt="{{ $tenantName }}">
        @endif
        <div class="org">{{ $tenantName }}</div>
        <h1>{{ $meeting->title }}</h1>
        <div class="meta">
            {{ $meeting->ended_at?->format('l, F j, Y') ?? $meeting->scheduled_at?->format('l, F j, Y') ?? 'N/A' }}
            &bull; Version {{ $minutes->version }}
        </div>
    </div>

    {!! $contentHtml !!}

    <div class="footer">
        {{ $tenantName }} &bull; {{ now()->format('M j, Y') }}
    </div>
</body>
</html>

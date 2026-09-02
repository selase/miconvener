<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans Mono', monospace; font-size: 11px; line-height: 1.5; padding: 20px; color: #333; }
        .header { background-color: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .header .org { font-size: 12px; color: {{ $primaryColor }}; font-weight: bold; }
        .header h1 { font-size: 18px; margin: 8px 0 5px 0; }
        .header .meta { font-size: 10px; color: #666; }
        .header .meta span { background: #e9ecef; padding: 2px 6px; border-radius: 3px; margin-right: 8px; }
        @if($logoUrl)
        .logo { max-height: 30px; margin-bottom: 5px; }
        @endif
        h1 { font-size: 16px; color: {{ $primaryColor }}; margin-top: 20px; }
        h1::before { content: "## "; color: #aaa; }
        h2 { font-size: 14px; margin-top: 18px; color: #555; }
        h2::before { content: "### "; color: #aaa; }
        h3 { font-size: 12px; margin-top: 12px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background-color: #343a40; color: #fff; }
        code { background: #f1f3f5; padding: 1px 4px; border-radius: 2px; font-size: 10px; }
        pre { background: #f1f3f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 10px; }
        ul, ol { padding-left: 20px; }
        li { margin-bottom: 3px; }
        .footer { margin-top: 25px; padding-top: 8px; border-top: 1px solid #dee2e6; font-size: 9px; color: #aaa; text-align: right; }
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
            <span>DATE: {{ $meeting->ended_at?->format('Y-m-d') ?? 'N/A' }}</span>
            <span>VERSION: v{{ $minutes->version }}</span>
            <span>STATUS: {{ strtoupper($minutes->status->value) }}</span>
            @if($minutes->ai_model)
                <span>MODEL: {{ $minutes->ai_model }}</span>
            @endif
        </div>
    </div>

    {!! $contentHtml !!}

    <div class="footer">
        {{ $tenantName }} // {{ now()->format('Y-m-d H:i:s') }} // v{{ $minutes->version }}
    </div>
</body>
</html>

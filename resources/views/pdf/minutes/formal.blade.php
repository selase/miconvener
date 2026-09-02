<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Serif', serif; font-size: 12px; line-height: 1.8; padding: 30px 40px; color: #222; }
        .header { text-align: center; border: 2px solid #333; padding: 20px; margin-bottom: 25px; }
        .header .org { font-size: 16px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: {{ $primaryColor }}; }
        .header h1 { font-size: 20px; margin: 10px 0 5px 0; }
        .header .meta { font-size: 11px; color: #555; }
        @if($logoUrl)
        .logo { max-height: 50px; margin-bottom: 10px; }
        @endif
        h1 { font-size: 18px; border-bottom: 1px solid #333; padding-bottom: 5px; text-transform: uppercase; letter-spacing: 1px; }
        h2 { font-size: 15px; margin-top: 20px; color: #333; border-left: 3px solid {{ $primaryColor }}; padding-left: 10px; }
        h3 { font-size: 13px; margin-top: 15px; font-style: italic; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #999; padding: 10px; text-align: left; }
        th { background-color: #eee; font-weight: bold; }
        ul, ol { padding-left: 25px; }
        .footer { margin-top: 40px; padding-top: 15px; border-top: 2px solid #333; font-size: 10px; color: #666; text-align: center; }
        .approval { margin-top: 30px; border-top: 1px dashed #999; padding-top: 15px; }
        .approval .line { border-bottom: 1px solid #333; width: 200px; display: inline-block; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="header">
        @if($logoUrl)
            <img src="{{ $logoUrl }}" class="logo" alt="{{ $tenantName }}">
        @endif
        <div class="org">{{ $tenantName }}</div>
        <h1>Official Meeting Minutes</h1>
        <div class="meta">
            <strong>{{ $meeting->title }}</strong><br>
            Date: {{ $meeting->ended_at?->format('F j, Y') ?? 'N/A' }}
            &nbsp;|&nbsp; Document Version: {{ $minutes->version }}
        </div>
    </div>

    {!! $contentHtml !!}

    @if($minutes->status->value === 'approved')
        <div class="approval">
            <p><strong>Approved by:</strong> {{ $minutes->approvedBy?->name ?? 'N/A' }}</p>
            <p><strong>Date of Approval:</strong> {{ $minutes->approved_at?->format('F j, Y') ?? 'N/A' }}</p>
            <p>Signature: <span class="line">&nbsp;</span></p>
        </div>
    @endif

    <div class="footer">
        Official Record &mdash; {{ $tenantName }} &mdash; {{ now()->format('F j, Y') }}
    </div>
</body>
</html>

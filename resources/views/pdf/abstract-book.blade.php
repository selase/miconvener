<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $event->title }} - Programme & Abstract Book</title>
    <style>
        @page {
            margin: 20mm 15mm 20mm 15mm;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1a202c;
            line-height: 1.5;
            font-size: 11pt;
        }
        .cover {
            text-align: center;
            padding-top: 120px;
            page-break-after: always;
        }
        .cover h1 {
            font-size: 28pt;
            color: #1e3a8a;
            margin-bottom: 12px;
            line-height: 1.2;
        }
        .cover .subtitle {
            font-size: 16pt;
            color: #4b5563;
            margin-bottom: 30px;
        }
        .cover .meta {
            font-size: 13pt;
            color: #6b7280;
            margin-top: 40px;
        }
        .page-break {
            page-break-after: always;
        }
        h2.section-header {
            font-size: 18pt;
            color: #1e3a8a;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 6px;
            margin-top: 30px;
            margin-bottom: 16px;
        }
        h3.track-header {
            font-size: 14pt;
            color: #1d4ed8;
            background-color: #eff6ff;
            padding: 6px 10px;
            border-left: 4px solid #2563eb;
            margin-top: 24px;
            margin-bottom: 14px;
        }
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .stats-table td {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            font-size: 10pt;
        }
        .stats-table .label {
            background-color: #f9fafb;
            font-weight: bold;
            width: 40%;
        }
        .session-card {
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 12px;
            background-color: #ffffff;
        }
        .session-time {
            font-weight: bold;
            color: #2563eb;
            font-size: 10pt;
        }
        .session-type-badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 8pt;
            font-weight: bold;
            background-color: #dbeafe;
            color: #1e40af;
            border-radius: 3px;
            text-transform: uppercase;
            margin-left: 6px;
        }
        .session-title {
            font-size: 12pt;
            font-weight: bold;
            color: #111827;
            margin-top: 3px;
            margin-bottom: 4px;
        }
        .session-speakers {
            font-size: 9pt;
            color: #4b5563;
        }
        .abstract-card {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px dashed #cbd5e1;
            page-break-inside: avoid;
        }
        .abstract-code {
            display: inline-block;
            font-size: 9pt;
            font-weight: bold;
            color: #ffffff;
            background-color: #1e3a8a;
            padding: 2px 8px;
            border-radius: 3px;
        }
        .abstract-pref {
            display: inline-block;
            font-size: 8pt;
            color: #047857;
            background-color: #d1fae5;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 6px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .abstract-title {
            font-size: 13pt;
            font-weight: bold;
            color: #1e293b;
            margin-top: 6px;
            margin-bottom: 4px;
        }
        .abstract-authors {
            font-size: 9.5pt;
            color: #334155;
            margin-bottom: 4px;
        }
        .abstract-author-presenting {
            text-decoration: underline;
            font-weight: bold;
        }
        .abstract-affiliations {
            font-size: 8.5pt;
            color: #64748b;
            font-style: italic;
            margin-bottom: 10px;
        }
        .abstract-section-title {
            font-weight: bold;
            color: #1e3a8a;
            font-size: 9.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .abstract-body {
            font-size: 9.5pt;
            color: #334155;
            text-align: justify;
            margin-bottom: 6px;
        }
        .abstract-keywords {
            font-size: 8.5pt;
            color: #64748b;
            margin-top: 8px;
        }
    </style>
</head>
<body>

    <!-- Cover Page -->
    <div class="cover">
        <h1>{{ $event->title }}</h1>
        <div class="subtitle">Official Scientific Programme & Abstract Book</div>
        <div class="meta">
            <p><strong>Dates:</strong> {{ $event->starts_at->format('F d, Y') }} - {{ $event->ends_at->format('F d, Y') }}</p>
            @if($event->address)
                <p><strong>Venue:</strong> {{ $event->address }}</p>
            @endif
        </div>
        <div style="margin-top: 100px; font-size: 10pt; color: #9ca3af;">
            Published by {{ config('app.name', 'MiConvener') }} &bull; All Rights Reserved
        </div>
    </div>

    <!-- Conference Overview & Summary -->
    <h2 class="section-header">Scientific Overview & Metrics</h2>
    <table class="stats-table">
        <tr>
            <td class="label">Total Accepted Abstracts</td>
            <td>{{ $summary['total_accepted'] }}</td>
        </tr>
        <tr>
            <td class="label">Oral Presentations</td>
            <td>{{ $summary['oral_count'] }}</td>
        </tr>
        <tr>
            <td class="label">Poster Presentations</td>
            <td>{{ $summary['poster_count'] }}</td>
        </tr>
        <tr>
            <td class="label">Scientific Tracks</td>
            <td>{{ $summary['tracks_count'] }}</td>
        </tr>
        <tr>
            <td class="label">Total Scientific Sessions</td>
            <td>{{ $summary['sessions_count'] }}</td>
        </tr>
    </table>

    <!-- Programme Schedule -->
    @if($sessions->isNotEmpty())
        <h2 class="section-header">Scientific Programme Schedule</h2>
        @foreach($sessions as $session)
            <div class="session-card">
                <span class="session-time">
                    {{ $session->starts_at->format('D, M d &bull; H:i') }} - {{ $session->ends_at->format('H:i') }}
                </span>
                <span class="session-type-badge">{{ str_replace('_', ' ', $session->type) }}</span>
                @if($session->location)
                    <span style="font-size: 9pt; color: #6b7280; margin-left: 8px;">&bull; Room: {{ $session->location }}</span>
                @endif
                <div class="session-title">{{ $session->title }}</div>
                @if($session->description)
                    <div style="font-size: 9pt; color: #4b5563; margin-bottom: 4px;">{{ $session->description }}</div>
                @endif
                @if($session->speakers->isNotEmpty())
                    <div class="session-speakers">
                        <strong>Speakers:</strong> {{ $session->speakers->pluck('name')->join(', ') }}
                    </div>
                @endif
            </div>
        @endforeach
        <div class="page-break"></div>
    @endif

    <!-- Accepted Abstracts by Track -->
    <h2 class="section-header">Accepted Scientific Abstracts</h2>

    @forelse($abstracts_by_track as $trackName => $trackAbstracts)
        <h3 class="track-header">{{ $trackName }} ({{ $trackAbstracts->count() }})</h3>

        @foreach($trackAbstracts as $abstract)
            <div class="abstract-card">
                <div>
                    <span class="abstract-code">{{ $abstract->code }}</span>
                    <span class="abstract-pref">{{ str_replace('_', ' ', $abstract->status) }}</span>
                </div>

                <div class="abstract-title">{{ $abstract->title }}</div>

                <div class="abstract-authors">
                    @foreach($abstract->authors as $author)
                        <span class="{{ $author->is_presenting ? 'abstract-author-presenting' : '' }}">
                            {{ $author->first_name }} {{ $author->last_name }}{{ $author->is_presenting ? '*' : '' }}
                        </span>{{ ! $loop->last ? ', ' : '' }}
                    @endforeach
                    @if($abstract->authors->contains('is_presenting', true))
                        <span style="font-size: 8pt; color: #64748b;">(*Presenting Author)</span>
                    @endif
                </div>

                <div class="abstract-affiliations">
                    {{ $abstract->authors->pluck('affiliation')->unique()->join('; ') }}
                </div>

                @if($abstract->structured_abstract && is_array($abstract->structured_abstract))
                    @if(!empty($abstract->structured_abstract['background']))
                        <div class="abstract-body">
                            <span class="abstract-section-title">Background:</span> {{ $abstract->structured_abstract['background'] }}
                        </div>
                    @endif
                    @if(!empty($abstract->structured_abstract['methods']))
                        <div class="abstract-body">
                            <span class="abstract-section-title">Methods:</span> {{ $abstract->structured_abstract['methods'] }}
                        </div>
                    @endif
                    @if(!empty($abstract->structured_abstract['results']))
                        <div class="abstract-body">
                            <span class="abstract-section-title">Results:</span> {{ $abstract->structured_abstract['results'] }}
                        </div>
                    @endif
                    @if(!empty($abstract->structured_abstract['conclusion']))
                        <div class="abstract-body">
                            <span class="abstract-section-title">Conclusion:</span> {{ $abstract->structured_abstract['conclusion'] }}
                        </div>
                    @endif
                @elseif($abstract->body)
                    <div class="abstract-body">
                        {{ $abstract->body }}
                    </div>
                @endif

                @if(!empty($abstract->keywords) && is_array($abstract->keywords))
                    <div class="abstract-keywords">
                        <strong>Keywords:</strong> {{ implode(', ', $abstract->keywords) }}
                    </div>
                @endif
            </div>
        @endforeach
    @empty
        <p style="color: #6b7280; font-style: italic;">No accepted abstracts published yet.</p>
    @endforelse

</body>
</html>

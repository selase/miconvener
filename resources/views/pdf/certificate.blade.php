<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Attendance — {{ $attendee->full_name }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #181c32;
            margin: 0;
            padding: 0;
        }
        .frame {
            border: 3px solid #181c32;
            padding: 20px;
            margin: 20px;
        }
        .inner {
            border: 1px solid #a1a5b7;
            padding: 60px 50px;
            text-align: center;
        }
        .eyebrow {
            font-size: 13px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #7e8299;
        }
        .title {
            margin-top: 18px;
            font-size: 34px;
            font-weight: bold;
        }
        .presented {
            margin-top: 40px;
            font-size: 13px;
            color: #7e8299;
        }
        .name {
            margin-top: 12px;
            font-size: 30px;
            font-weight: bold;
            color: #009EF7;
        }
        .body-text {
            margin-top: 30px;
            font-size: 14px;
            line-height: 1.7;
            color: #3f4254;
        }
        .event-name {
            font-weight: bold;
        }
        .footer {
            margin-top: 60px;
            font-size: 11px;
            color: #a1a5b7;
        }
    </style>
</head>
<body>
    <div class="frame">
        <div class="inner">
            <div class="eyebrow">Certificate</div>
            <div class="title">Of Attendance</div>

            <div class="presented">This certifies that</div>
            <div class="name">{{ $attendee->full_name }}</div>

            <div class="body-text">
                attended <span class="event-name">{{ $event->name }}</span><br>
                {{ $event->starts_at->format('F j, Y') }}
                @if(! $event->starts_at->isSameDay($event->ends_at))
                    &ndash; {{ $event->ends_at->format('F j, Y') }}
                @endif
            </div>

            <div class="footer">
                Ticket {{ $attendee->ticket_code }} &middot; Issued {{ now()->format('F j, Y') }}
            </div>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Ticket — {{ $event->name }}</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1b1b1b; margin: 0; padding: 0; font-size: 13px; line-height: 1.55; }
        .sheet { padding: 44px 48px; }
        .org { font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase; color: #6b7280; }
        h1 { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 27px; font-weight: 700; margin: 6px 0 2px; }
        .when { color: #4b5563; font-size: 13px; margin-bottom: 26px; }
        .rule { border: 0; border-top: 1px solid #e5e7eb; margin: 0 0 26px; }
        .grid { width: 100%; }
        .grid td { vertical-align: top; }
        .qr-cell { width: 210px; padding-right: 30px; }
        .qr { width: 200px; height: 200px; display: block; }
        .code-label { font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: #6b7280; margin-top: 12px; }
        .code { font-family: 'Courier New', monospace; font-size: 19px; font-weight: 700; margin-top: 2px; }
        .field { margin-bottom: 13px; }
        .field .k { font-size: 10px; letter-spacing: 0.13em; text-transform: uppercase; color: #6b7280; }
        .field .v { font-size: 14px; margin-top: 1px; }
        .note { margin-top: 30px; padding-top: 18px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 11.5px; }
        .issuer { margin-top: 26px; }
        .issuer img { height: 20px; width: auto; }
        .issuer .by { font-size: 10px; letter-spacing: 0.13em; text-transform: uppercase; color: #9ca3af; margin-bottom: 5px; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="org">{{ $tenant->name }}</div>
    <h1>{{ $event->name }}</h1>
    <div class="when">
        {{ $event->starts_at->timezone($event->timezone)->format('l, j F Y') }}
        &middot;
        {{ $event->starts_at->timezone($event->timezone)->format('g:i A') }}
        ({{ $event->timezone }})
    </div>

    <hr class="rule">

    <table class="grid">
        <tr>
            <td class="qr-cell">
                @if ($qrDataUri)
                    <img class="qr" src="{{ $qrDataUri }}" alt="Entry QR code">
                @endif
                <div class="code-label">Entry code</div>
                <div class="code">{{ $registration->ticket_code }}</div>
            </td>
            <td>
                <div class="field">
                    <div class="k">Attendee</div>
                    <div class="v">{{ $registration->full_name }}</div>
                </div>
                <div class="field">
                    <div class="k">Email</div>
                    <div class="v">{{ $registration->email }}</div>
                </div>
                @if ($registration->ticketType)
                    <div class="field">
                        <div class="k">Ticket</div>
                        <div class="v">{{ $registration->ticketType->name }}</div>
                    </div>
                @endif
                <div class="field">
                    <div class="k">{{ $event->location_type === 'virtual' ? 'Join at' : 'Venue' }}</div>
                    <div class="v">{{ $event->location_type === 'virtual' ? $event->virtual_link : $event->address }}</div>
                </div>
                <div class="field">
                    <div class="k">Status</div>
                    <div class="v">{{ ucfirst(str_replace('_', ' ', $registration->status)) }}</div>
                </div>
            </td>
        </tr>
    </table>

    @if ($brandDataUri)
        <div class="issuer">
            <div class="by">Ticketing by</div>
            <img src="{{ $brandDataUri }}" alt="{{ config('app.name') }}">
        </div>
    @endif

    <div class="note">
        Show the QR code at the entrance. If it will not scan, give your entry code
        <strong>{{ $registration->ticket_code }}</strong> — the team can also find you by name or email.
        This ticket admits one person and is valid only for the event above.
    </div>
</div>
</body>
</html>

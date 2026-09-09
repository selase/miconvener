<x-mail::message>
# You're confirmed, {{ $registration->full_name }}!

Your registration for **{{ $event->name }}** is confirmed.

- **When:** {{ $event->starts_at->timezone($event->timezone)->format('l, F j, Y \a\t g:i A') }} ({{ $event->timezone }})
- **Where:** {{ $event->location_type === 'virtual' ? $event->virtual_link : $event->address }}
- **Ticket code:** {{ $registration->ticket_code }}

@if ($qrPng)
<img src="{{ $message->embedData($qrPng, 'ticket-qr.png', 'image/png') }}" alt="Your ticket QR code" width="200" height="200">
@elseif ($qrImage)
<img src="{{ $qrImage }}" alt="Your ticket QR code" width="200" height="200">
@endif

Show this QR code at check-in, or give your ticket code **{{ $registration->ticket_code }}** — the team can find you by code, name or email if the code will not scan.

<x-mail::button :url="$portalUrl">
View your ticket
</x-mail::button>

You can also transfer this ticket or build your personal schedule from that page.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

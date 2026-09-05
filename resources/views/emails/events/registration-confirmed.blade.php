<x-mail::message>
# You're confirmed, {{ $registration->full_name }}!

Your registration for **{{ $event->name }}** is confirmed.

- **When:** {{ $event->starts_at->timezone($event->timezone)->format('l, F j, Y \a\t g:i A') }} ({{ $event->timezone }})
- **Where:** {{ $event->location_type === 'virtual' ? $event->virtual_link : $event->address }}
- **Ticket code:** {{ $registration->ticket_code }}

<img src="{{ $qrImage }}" alt="Your ticket QR code" width="200" height="200">

Show this QR code (or your ticket code) at check-in.

<x-mail::button :url="$portalUrl">
View your ticket
</x-mail::button>

You can also transfer this ticket or build your personal schedule from that page.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

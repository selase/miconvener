<x-mail::message>
# One more step, {{ $registration->full_name }}

You're on the list for **{{ $event->name }}**. Confirm this email address and we'll send your ticket.

<x-mail::button :url="$verifyUrl">
Confirm my email
</x-mail::button>

- **When:** {{ $event->starts_at->timezone($event->timezone)->format('l, F j, Y \a\t g:i A') }} ({{ $event->timezone }})
- **Where:** {{ $event->location_type === 'virtual' ? 'Online' : $event->address }}

Your entry code and QR arrive as soon as you confirm. The link works for seven days.

If you didn't register for this, ignore this email — no ticket will be issued.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

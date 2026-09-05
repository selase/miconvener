<x-mail::message>
# Hi {{ $registration->full_name }}

**{{ $event->name }}** is at capacity right now, so we've added you to the waitlist — you're number **{{ $registration->waitlist_position }}** in line.

If a spot opens up, we'll email you straight away.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

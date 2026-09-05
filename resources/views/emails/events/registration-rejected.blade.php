<x-mail::message>
# Hi {{ $registration->full_name }}

The organizers weren't able to confirm your registration for **{{ $event->name }}** this time.

@if ($registration->approval_note)
> {{ $registration->approval_note }}
@endif

If you think this is a mistake, reply to this email and let the organizers know.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

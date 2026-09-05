<x-mail::message>
# Thanks, {{ $registration->full_name }}

We've received your registration for **{{ $event->name }}**.

The organizers review registrations before confirming a spot. We'll email you as soon as yours is reviewed — usually within a few days.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

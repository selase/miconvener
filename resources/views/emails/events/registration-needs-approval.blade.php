<x-mail::message>
# Someone is waiting to be approved

**{{ $registration->full_name }}** registered for **{{ $event->name }}** and
is waiting for you to approve or decline them. They have been told you review
registrations before confirming a spot.

<x-mail::panel>
{{ $registration->full_name }}<br>
{{ $registration->email }}@if ($registration->ticket_type_name)<br>
{{ $registration->ticket_type_name }}@endif
</x-mail::panel>

<x-mail::button :url="$reviewUrl">
Review registrations
</x-mail::button>

@if ($registration->amount > 0)
They have not paid yet, and cannot: approving them sends them a payment link,
and their spot is held only once that payment goes through.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

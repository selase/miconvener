<x-mail::message>
# Someone needs help now

An attendee at **{{ $event->name }}** has raised an urgent request. It is
showing in your console, but this is sent in case nobody is watching the screen.

<x-mail::panel>
**{{ $serviceRequest->type === 'medical' ? 'First aid / medical' : 'Urgent request' }}**
@if ($attendeeName)

{{ $attendeeName }}
@endif
@if ($seatLabel)

Seat {{ $seatLabel }}@if ($roomName), {{ $roomName }}@endif
@endif
@if ($serviceRequest->location)

Location given: {{ $serviceRequest->location }}
@endif

Raised at {{ $serviceRequest->created_at->timezone($event->timezone)->format('H:i') }}
</x-mail::panel>

@if ($serviceRequest->note)
> {{ $serviceRequest->note }}
@endif

<x-mail::button :url="$requestsUrl">
Open requests
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

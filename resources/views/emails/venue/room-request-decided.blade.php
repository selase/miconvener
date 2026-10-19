<x-mail::message>
# {{ match ($outcome) { 'approved' => 'Your room is booked', 'declined' => 'Your request was declined', default => 'Your booking was cancelled' } }}

<x-mail::panel>
**{{ $booking->listing?->title }}**<br>
{{ $booking->starts_at->format('l j F Y, H:i') }} to {{ $booking->ends_at->format('H:i') }}<br>
@if ($booking->purpose)
{{ $booking->purpose }}
@endif
</x-mail::panel>

@if ($outcome === 'approved')
The facility team has approved your request. If your plans change, please cancel it so others can use the room.
@elseif ($outcome === 'declined')
@if ($reason)
Reason: {{ $reason }}
@endif

You are welcome to request another time.
@else
@if ($reason)
Reason: {{ $reason }}
@endif
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

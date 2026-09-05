<x-mail::message>
# Hi {{ $registration->full_name }}

@if ($reason === 'approved')
Your registration for **{{ $event->name }}** has been approved. One last step — complete payment to secure your spot.
@else
A spot has opened up for **{{ $event->name }}** and it's yours. Complete payment to secure it.
@endif

<x-mail::button :url="$checkoutUrl">
Complete payment
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

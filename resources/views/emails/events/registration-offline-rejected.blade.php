<x-mail::message>
# Hi {{ $registration->full_name }}

The organizer was unable to verify your offline payment for **{{ $event->name }}**.

@if (filled($reason))
**Note from the organizer:**  
> {{ $reason }}
@endif

You can re-upload an updated receipt or pay online directly with card or mobile money to secure your ticket:

<x-mail::button :url="$checkoutUrl">
Update payment / Pay online
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

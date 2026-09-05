<x-mail::message>
Hi {{ $recipientName }},

{{ $bodyText }}

—

Sent to registrants of **{{ $event->name }}**.

Thanks,<br>
{{ config('app.name') }}

<img src="{{ $trackingUrl }}" width="1" height="1" alt="" style="display:none">
</x-mail::message>

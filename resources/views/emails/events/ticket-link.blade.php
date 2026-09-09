<x-mail::message>
# Here's your ticket

Someone asked for the ticket link for **{{ $event->name }}** to be sent to this address.

<x-mail::button :url="$portalUrl">
Open my ticket
</x-mail::button>

It shows your QR code and entry code, and lets you build your schedule for the day.

If you did not ask for this, you can ignore it — nothing has changed.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

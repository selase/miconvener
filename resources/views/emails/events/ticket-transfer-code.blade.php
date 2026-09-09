<x-mail::message>
# Confirm this transfer

Someone asked to transfer your ticket for **{{ $event->name }}** to:

- **Name:** {{ $transfer->to_full_name }}
- **Email:** {{ $transfer->to_email }}

Enter this code on the ticket page to complete it:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

The code expires in {{ \App\Models\EventRegistrationTransfer::TTL_MINUTES }} minutes.

**If you did not ask for this, ignore this email** — your ticket stays exactly as it is, and nobody can move it without this code.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

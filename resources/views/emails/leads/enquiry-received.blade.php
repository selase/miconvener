<x-mail::message>
# Thank you, {{ $lead->name }}

We have your enquiry{{ $lead->company ? ' for '.$lead->company : '' }} and someone from our team will be in touch soon.

If there is anything to add, such as dates, numbers of attendees or what you are planning, just reply to this email.

<x-mail::panel>
{{ $lead->message }}
</x-mail::panel>

Thanks,<br>
The {{ config('app.name') }} team
</x-mail::message>

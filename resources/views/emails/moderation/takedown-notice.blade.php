<x-mail::message>
@php($what = $kind === 'event' ? 'event' : 'marketplace listing')
@if ($restored)
# Your {{ $what }} is back

We have restored **{{ $itemName }}** for **{{ $organisationName }}**. It is back to the status it had before we took it down, and you can manage it as usual.
@else
# We have taken down your {{ $what }}

We have taken down **{{ $itemName }}** for **{{ $organisationName }}**. It is hidden from the public, and it cannot be published again until we restore it.

@if ($reason)
**Why:** {{ $reason }}
@endif

You can still sign in and edit it. If you think this is a mistake, or you have fixed the problem, reply to this email and we will look at it again.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

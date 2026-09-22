<x-mail::message>
# Your code

Someone asked to see their events with **{{ $organiser }}** using this address.

<x-mail::panel>
{{ $code }}
</x-mail::panel>

Enter it on the page you came from. It works once, and expires in {{ $minutes }} minutes.

**If you did not ask for this, ignore this email.** Nobody can use this address without the code.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

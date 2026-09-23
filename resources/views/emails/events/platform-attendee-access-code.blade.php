<x-mail::message>
# Your sign-in code

Here is your verification code to access your events on MiConvener:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

Enter it on the page you came from. It works once and expires in {{ $minutes }} minutes.

**If you did not request this, you can ignore this email.** Nobody can access your events without this code.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

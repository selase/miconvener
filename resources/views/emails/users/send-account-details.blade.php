<x-mail::message>
# Hello {{ $user }},

Your account is ready. Choose your own password using account recovery. For your security, we do not send passwords by email.

<x-mail::button :url="rtrim(config('app.url'), '/').'/forgot-password'">
Set up your password
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

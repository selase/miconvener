<x-mail::message>
# Hello {{ $data['user'] }},

Use account recovery to choose a password securely. This email does not include a password.

<x-mail::button :url="rtrim(config('app.url'), '/').'/forgot-password'">
Recover your account
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

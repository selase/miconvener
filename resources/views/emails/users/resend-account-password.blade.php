<x-mail::message>
# Hello {{ $data['user'] }},

Your account has been given a brand-new password.
To access the application, kindly use the information below.

**Email:** {{ $data['email'] }} <br> **Password:** {{ $data['password'] }}

<x-mail::button :url="route('login')">
Login
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

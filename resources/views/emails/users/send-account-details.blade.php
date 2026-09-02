@component('mail::message')
# Hello {{ $user }},

Congratulations! your account has been created for {{ config('app.name') }}.
Below are the details:

**Email:** {{ $email }} <br> **Password:** {{ $password }}


@component('mail::button', ['url' => '/login'])
Login
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent

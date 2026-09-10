@component('mail::message')
# Hello {{ $user }},

Congratulations! your account has been created for {{ $tenantName ? $tenantName.' on '.config('app.name') : config('app.name') }}.
Below are the details:

**Email:** {{ $email }} <br> **Password:** {{ $password }}


@component('mail::button', ['url' => $loginUrl ?? route('login')])
Login
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent

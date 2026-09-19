<x-mail::message>
# Set up your recovery codes

Hi {{ $firstName }},

You use an authenticator app to sign in to **{{ $organizationName }}**, and
{{ $organizationName }} requires it. Right now there is no backup: if you lose
the phone with your authenticator on it, you cannot sign in at all.

Recovery codes fix that. Each one signs you in once when your authenticator
isn't available.

<x-mail::button :url="$accountUrl">
Generate my recovery codes
</x-mail::button>

You'll be asked for your password, then shown eight codes. Save them somewhere
you can reach without your phone — a password manager, or printed. They are
shown once and we cannot show them again, though you can always generate a new
set from the same page.

Please do this while you still have your authenticator to hand.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"></head>
<body>
<p>You have been invited to join {{ $organisationName }} on {{ config('app.name') }}.</p>
<p><a href="{{ $acceptUrl }}">Review invitation</a></p>
<p>This invitation expires {{ $expiresAt->toDayDateTimeString() }}. Opening the link does not grant access. Accept only if you wish to join this organisation.</p>
<p>Use your existing account to accept, or choose your own password when creating an account. We never send passwords by email.</p>
</body></html>

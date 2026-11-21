<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"></head>
<body>
<p>You requested a change to your {{ config('app.name') }} account email.</p>
<p><a href="{{ $confirmationUrl }}">Verify your new email address</a></p>
<p>This link expires {{ $expiresAt->toDayDateTimeString() }}. Opening it does not change your account. Sign in to the same account and confirm your current password to finish.</p>
<p>If you did not request this change, ignore this email. No changes have been made.</p>
</body></html>

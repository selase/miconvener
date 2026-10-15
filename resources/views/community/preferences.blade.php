<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Announcement preferences · {{ $organisation }}</title></head>
<body>
<main>
    <h1>{{ $organisation }} announcement preferences</h1>
    @if($disabled)
        <p role="status">Announcements are switched off for this address. Service notices, receipts and sign-in codes are unaffected.</p>
    @else
        <p>Stop announcements from this organisation for the address that received this link. Service notices, receipts and sign-in codes are unaffected.</p>
        <form method="post">@csrf<button type="submit">Stop announcements</button></form>
    @endif
</main>
</body>
</html>

<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Announcement preferences · {{ $organisation }}</title><style>
:root{color-scheme:light dark;font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033}body{margin:0;padding:8vh 20px}main{box-sizing:border-box;max-width:600px;margin:auto;padding:32px;border:1px solid #dbe1ea;border-radius:12px;background:#fff}h1{font-size:24px;line-height:1.3}p{line-height:1.7;color:#475569}button{font:inherit;background:#1549c2;color:white;border:0;border-radius:6px;padding:12px 20px;cursor:pointer}button:focus-visible{outline:3px solid #568cff;outline-offset:3px}@media(prefers-color-scheme:dark){:root{background:#10151d;color:#e9edf4}main{background:#171f2c;border-color:#344155}p{color:#bdc9d9}}
</style></head>
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

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Confirm your new email · {{ config('app.name') }}</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-canvas px-4 py-10 font-sans text-ink">
<main class="mx-auto max-w-lg rounded-lg border border-border bg-surface p-6 shadow-sm sm:p-8">
    <h1 class="text-xl font-semibold">Confirm your new email</h1>
    @if ($change)
        <p class="mt-4 text-sm text-ink-secondary">Change your account email to <span class="break-all font-semibold">{{ $change['email'] }}</span>.</p>
        <p class="mt-4 text-sm text-ink-secondary">Opening this page does not change your account. Confirm your current password{{ $mfaRequired ? ' and authenticator code' : '' }} to continue.</p>
        <p class="mt-4 text-sm text-ink-secondary">Your organisation access stays with the same account. Attendee receipts and history keep their original email ownership.</p>
        <p id="email-change-error" role="alert" class="mt-4 text-sm text-danger-fg">{{ $errors->first() }}</p>
        <p id="email-change-status" role="status" class="mt-4 text-sm text-success-fg"></p>
        <noscript><p class="mt-4 text-sm text-ink-secondary">Enable JavaScript and reopen the complete verification link from your new mailbox to continue securely.</p></noscript>
        <form class="mt-6 space-y-3" id="email-change-form" method="POST" action="{{ url('/account-identity/email-changes/'.$change['id'].'/confirm') }}">
            @csrf
            <input type="hidden" name="token" id="email-change-token" autocomplete="off">
            <label class="block text-sm font-medium" for="password">Current password</label>
            <input class="w-full rounded-lg border border-border bg-canvas px-3 py-2 text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" id="password" name="password" type="password" required autocomplete="current-password" maxlength="1024">
            @if ($mfaRequired)
                <label class="block text-sm font-medium" for="code">Authenticator code</label>
                <input class="w-full rounded-lg border border-border bg-canvas px-3 py-2 text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
            @endif
            <button class="inline-flex items-center rounded-md bg-accent px-4 py-2 text-sm font-medium text-accent-ink disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" type="submit" id="email-change-submit" disabled>Confirm email change</button>
        </form>
        <script>
            (() => {
                const form = document.getElementById('email-change-form');
                const error = document.getElementById('email-change-error');
                const status = document.getElementById('email-change-status');
                const submit = document.getElementById('email-change-submit');
                const token = window.location.hash.slice(1);
                window.history.replaceState(null, '', window.location.pathname);
                if (!/^[a-f0-9]{64}$/.test(token)) {
                    error.textContent = 'Open the complete verification link from your new mailbox to continue.';
                    return;
                }
                document.getElementById('email-change-token').value = token;
                submit.disabled = false;
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    submit.disabled = true;
                    error.textContent = '';
                    try {
                        const response = await fetch(form.action, {
                            method: 'POST', body: new FormData(form), credentials: 'same-origin',
                            headers: { Accept: 'application/json' },
                        });
                        const data = await response.json();
                        if (!response.ok) {
                            error.textContent = Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Unable to confirm this email change.';
                        } else {
                            form.hidden = true;
                            status.textContent = data.message;
                        }
                    } catch {
                        error.textContent = 'Unable to confirm. Check your connection and try again. If the change already succeeded, sign in using your new email.';
                    } finally {
                        form.querySelector('[name="password"]').value = '';
                        const code = form.querySelector('[name="code"]');
                        if (code) { code.value = ''; }
                        submit.disabled = false;
                    }
                });
            })();
        </script>
    @else
        <p role="status" class="mt-4 text-sm text-ink">{{ $message }}</p>
    @endif
    @if (\Illuminate\Support\Facades\Route::has('workspaces.index'))
        <p class="mt-4 text-sm text-ink-secondary"><a class="font-medium text-accent underline underline-offset-4" href="{{ route('workspaces.index') }}">Return to your workspaces</a></p>
    @endif
</main>
</body>
</html>

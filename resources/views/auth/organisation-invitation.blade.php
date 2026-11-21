<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Organisation invitation · {{ config('app.name') }}</title>
    @vite('resources/css/app.css')
    @if ($invitationId)
        <script>
            (() => {
                const fragment = window.location.hash.slice(1);
                const fragmentToken = /^[a-f0-9]{64}$/.test(fragment) ? fragment : '';
                if (window.location.hash) {
                    window.history.replaceState(null, '', window.location.pathname);
                }
                document.addEventListener('DOMContentLoaded', () => {
                    const inputs = document.querySelectorAll('input[name="token"]');
                    if (fragmentToken) {
                        inputs.forEach(input => { input.value = fragmentToken; });
                    }
                    const ready = inputs.length > 0 && /^[a-f0-9]{64}$/.test(inputs[0].value);
                    document.querySelectorAll('[data-invitation-action]').forEach(button => { button.disabled = !ready; });
                    const notice = document.getElementById('invitation-proof-status');
                    if (notice) { notice.hidden = ready; }
                }, { once: true });
            })();
        </script>
    @endif
</head>
<body class="console-root min-h-screen bg-canvas font-console text-ink">
<main class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
    <section class="w-full max-w-lg space-y-6 rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-8" aria-labelledby="invitation-heading">
        <header class="space-y-2">
            <p class="text-xs font-semibold uppercase tracking-widest text-ink-tertiary">{{ config('app.name') }}</p>
            <h1 id="invitation-heading" class="text-2xl font-semibold tracking-tight">{{ $invitationId ? 'Organisation invitation' : 'Invitation accepted' }}</h1>
        </header>
        @if (session('status'))
            <p class="rounded-lg border border-border bg-canvas px-4 py-3 text-sm" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="rounded-lg border border-border bg-canvas px-4 py-3 text-sm text-danger-fg" role="alert">{{ $errors->first() }}</p>
        @endif
        @if ($invitationId)
            <p id="invitation-proof-status" class="text-sm text-ink-secondary" hidden>Reopen the invitation link from your email to continue securely.</p>
            <p class="text-sm leading-relaxed text-ink-secondary">You have been invited to join <strong class="font-semibold text-ink">{{ $organisationName }}</strong>. Opening this page does not grant access.</p>
            @if ($wrongAccount ?? false)
                <div class="space-y-4 rounded-xl border border-border bg-canvas p-4">
                    <h2 class="text-base font-semibold">Sign in with the invited account</h2>
                    <p class="text-sm text-ink-secondary">You are signed in with a different account. Switch accounts explicitly to continue. Opening this page keeps your current account signed in.</p>
                    <form method="POST" action="{{ route('organisation-invitations.accept', ['invitation' => $invitationId]) }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <input type="hidden" name="intent" value="switch-account">
                        <button data-invitation-action class="min-h-11 w-full rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" type="submit">Switch account and sign in</button>
                    </form>
                </div>
            @else
            @guest
                <div class="space-y-3 border-b border-border pb-6">
                    <p class="text-sm text-ink-secondary">Already have an account? Sign in using your invited email address, then return here to accept.</p>
                    <form method="POST" action="{{ route('organisation-invitations.accept', ['invitation' => $invitationId]) }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <input type="hidden" name="intent" value="signin">
                        <button data-invitation-action class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" type="submit">Sign in</button>
                    </form>
                </div>
            @endguest
            @if (auth()->check() && ($mfaRequired ?? false) && ! auth()->user()->two_factor_confirmed_at)
                <section class="space-y-4 rounded-xl border border-border bg-canvas p-4" aria-labelledby="mfa-heading">
                    <h2 id="mfa-heading" class="text-base font-semibold">Set up two-factor authentication</h2>
                    <p class="text-sm text-ink-secondary">This organisation requires an authenticator app before you can join.</p>
                    @if ($mfaSecret)
                        <p class="text-sm text-ink-secondary">Add a new entry in your authenticator app using this setup key. Keep it private:</p>
                        <p class="break-all rounded-lg border border-border bg-surface p-3 text-sm"><code>{{ $mfaSecret }}</code></p>
                        <form class="space-y-4" method="POST" action="{{ route('organisation-invitations.mfa.confirm', ['invitation' => $invitationId]) }}">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <div class="space-y-2">
                                <label class="block text-sm font-medium" for="code">Six-digit authenticator code</label>
                                <input class="block min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:border-accent focus:outline-2 focus:outline-offset-2 focus:outline-accent" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
                            </div>
                            <button data-invitation-action class="min-h-11 w-full rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" type="submit">Confirm two-factor authentication</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('organisation-invitations.mfa.setup', ['invitation' => $invitationId]) }}">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <button data-invitation-action class="min-h-11 w-full rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" type="submit">Set up authenticator</button>
                        </form>
                    @endif
                </section>
            @endif
            <form class="space-y-5" method="POST" action="{{ route('organisation-invitations.accept', ['invitation' => $invitationId]) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                @guest
                    <div class="space-y-2">
                        <h2 class="text-base font-semibold">Create an account and accept</h2>
                        <p class="text-sm text-ink-secondary">Your invitation verifies the invited mailbox. Choose your own password.</p>
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium" for="first_name">First name</label>
                        <input class="block min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:border-accent focus:outline-2 focus:outline-offset-2 focus:outline-accent" id="first_name" name="first_name" required maxlength="255" autocomplete="given-name" value="{{ old('first_name') }}">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium" for="last_name">Last name</label>
                        <input class="block min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:border-accent focus:outline-2 focus:outline-offset-2 focus:outline-accent" id="last_name" name="last_name" required maxlength="255" autocomplete="family-name" value="{{ old('last_name') }}">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium" for="password">Password</label>
                        <input class="block min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:border-accent focus:outline-2 focus:outline-offset-2 focus:outline-accent" type="password" id="password" name="password" required autocomplete="new-password">
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium" for="password_confirmation">Confirm password</label>
                        <input class="block min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:border-accent focus:outline-2 focus:outline-offset-2 focus:outline-accent" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
                    </div>
                @endguest
                <button data-invitation-action class="min-h-11 w-full rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" type="submit">Accept invitation</button>
            </form>
            @endif
        @else
            <a class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" href="{{ \Illuminate\Support\Facades\Route::has('workspaces.index') ? route('workspaces.index') : url('/dashboard') }}">Continue to your account</a>
        @endif
    </section>
</main>
</body>
</html>

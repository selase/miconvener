<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enum\TenantStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptOrganisationInvitationRequest;
use App\Http\Requests\Auth\ConfirmInvitationMfaRequest;
use App\Http\Requests\Auth\ManageOrganisationInvitationRequest;
use App\Http\Requests\Auth\OrganisationInvitationTokenRequest;
use App\Http\Requests\Auth\SetupInvitationMfaRequest;
use App\Models\OrganisationInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\OrganisationInvitations;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class OrganisationInvitationController extends Controller
{
    public function __construct(private readonly OrganisationInvitations $invitations) {}

    /** @param array<array-key, mixed> $parameters */
    public function callAction($method, $parameters): mixed
    {
        try {
            $response = parent::callAction($method, $parameters);
        } catch (ValidationException $exception) {
            foreach (['token', 'code', 'secret'] as $sensitiveInput) {
                request()->request->remove($sensitiveInput);
            }

            throw $exception;
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function show(Request $request, OrganisationInvitation $invitation): Response
    {
        abort_unless($invitation->status === 'pending' && $invitation->expires_at->isFuture(), 410);
        $wrongAccount = $request->user() && mb_strtolower($request->user()->email) !== $invitation->email;
        $token = null;
        $pending = $request->session()->get('organisation_invitation');
        if (is_array($pending) && ($pending['id'] ?? null) === $invitation->id && is_string($pending['token'] ?? null)) {
            $candidate = Crypt::decryptString($pending['token']);
            if (hash_equals((string) $invitation->token_hash, hash('sha256', $candidate))) {
                $token = $candidate;
            }
        }

        return response()->view('auth.organisation-invitation', [
            'organisationName' => $invitation->tenant->name, 'invitationId' => $invitation->id, 'token' => $token,
            'wrongAccount' => $wrongAccount, 'mfaRequired' => (bool) $invitation->tenant->require_2fa,
            'mfaSecret' => $token ? $request->session()->get('organisation_invitation_mfa_secret') : null,
        ]);
    }

    public function resume(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('organisation_invitation');
        abort_unless(is_array($pending) && is_string($pending['id'] ?? null), 404);

        return redirect()->route('organisation-invitations.show', ['invitation' => $pending['id']]);
    }

    public function accept(AcceptOrganisationInvitationRequest $request, OrganisationInvitation $invitation): RedirectResponse
    {
        abort_unless(config('organisation_access.enabled', false), 503, 'Organisation invitations are currently paused.');
        $invitation = $this->postedInvitation($request, $invitation);
        $token = $request->validated('token');
        $actor = $request->user();
        $wrongAccount = $actor && mb_strtolower($actor->email) !== $invitation->email;
        $intent = $request->validated('intent');
        if ($actor && ($intent === 'switch-account' || ($wrongAccount && $intent === 'signin'))) {
            $this->remember($request, $invitation, $token);
            \App\Services\Auth\StaffSessions::logoutCurrentDevice();
            $request->session()->forget([
                \App\Http\Middleware\StaffAccountSecurity::SESSION_KEY, 'google2fa', 'two_factor_setup_secret',
                'auth.password_confirmed_at', 'active_tenant_id', 'original_user', 'original_security_version',
                'original_active_tenant_id', 'impersonation_tenant_id', 'organisation_invitation_mfa_secret', 'organisation_invitation_mfa_user',
            ]);
            $request->session()->regenerate(true);
            $request->session()->put('url.intended', route('organisation-invitations.resume'));

            return redirect()->route('login');
        }
        abort_if($wrongAccount, 403, 'Sign in with the invited account to continue.');

        $this->remember($request, $invitation, $token);
        if (! $actor && (in_array($intent, ['signin', 'switch-account'], true) || User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->exists())) {
            $request->session()->put('url.intended', route('organisation-invitations.resume'));

            return redirect()->route('login')->with('status', 'Sign in and verify your invited email to continue.');
        }
        if ($actor && $intent === 'signin') {
            return redirect()->route('organisation-invitations.show', ['invitation' => $invitation->id]);
        }
        if ($actor && ! $actor->email_verified_at) {
            $request->session()->put('url.intended', route('organisation-invitations.resume'));

            return redirect()->route('verification.notice');
        }
        if ($actor && $actor->two_factor_secret && $actor->two_factor_confirmed_at && ! $request->session()->has('google2fa')) {
            $request->session()->put('url.intended', route('organisation-invitations.resume'));

            return redirect()->route('two-factor.challenge');
        }
        $needsMfa = $invitation->tenant->require_2fa && (! $actor?->two_factor_confirmed_at || ! $request->session()->has('google2fa'));
        $newUser = $actor === null;
        DB::connection('landlord')->transaction(function () use ($invitation, $token, $request, $needsMfa, &$actor): void {
            $tenant = Tenant::query()->whereKey($invitation->tenant_id)->lockForUpdate()->firstOrFail();
            abort_unless($tenant->status === TenantStatusEnum::ACTIVE, 403);
            $this->invitations->pendingToken($token);
            if (! $actor) {
                abort_if(User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->exists(), 409, 'Sign in to your existing account.');
                $actor = User::query()->create([
                    'first_name' => $request->validated('first_name'), 'last_name' => $request->validated('last_name'),
                    'email' => $invitation->email, 'password' => Hash::make($request->validated('password')), 'status' => User::STATUS_ACTIVE,
                ]);
                $actor->forceFill(['email_verified_at' => now()])->save();
            }
            if (! $needsMfa) {
                $this->invitations->accept($token, $actor);
            }
        });
        abort_unless($actor instanceof User, 500);
        if ($newUser) {
            Auth::login($actor);
            $request->session()->regenerate(true);
        }
        if ($needsMfa) {
            return redirect()->route('organisation-invitations.show', ['invitation' => $invitation->id])->with('status', 'Your account is ready. Set up two-factor authentication below, then accept the invitation.');
        }
        $request->session()->forget('organisation_invitation');

        return redirect()->route('organisation-invitations.complete')->with('status', 'Invitation accepted. Your organisation access is ready.');
    }

    public function setupMfa(SetupInvitationMfaRequest $request, OrganisationInvitation $invitation): RedirectResponse
    {
        $invitation = $this->postedInvitation($request, $invitation);
        $this->assertInviteIdentity($invitation, $request->user()->fresh());
        abort_if($request->user()->two_factor_confirmed_at !== null, 409);
        $this->remember($request, $invitation, $request->validated('token'));
        $request->session()->put('organisation_invitation_mfa_secret', app('pragmarx.google2fa')->generateSecretKey());
        $request->session()->put('organisation_invitation_mfa_user', $request->user()->id);

        return redirect()->route('organisation-invitations.show', ['invitation' => $invitation->id]);
    }

    public function confirmMfa(ConfirmInvitationMfaRequest $request, OrganisationInvitation $invitation): RedirectResponse
    {
        $invitation = $this->postedInvitation($request, $invitation);
        $this->assertInviteIdentity($invitation, $request->user()->fresh());
        $secret = $request->session()->get('organisation_invitation_mfa_secret');
        abort_unless($secret && (string) $request->session()->get('organisation_invitation_mfa_user') === (string) $request->user()->id, 403);
        if (! app('pragmarx.google2fa')->verifyKey($secret, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid.']);
        }
        DB::connection('landlord')->transaction(function () use ($request, $secret, $invitation): void {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! in_array($actor->status, [null, User::STATUS_ACTIVE], true)
                || (int) $actor->security_version !== (int) $request->user()->security_version) {
                throw new AuthenticationException;
            }
            $this->assertInviteIdentity($invitation, $actor);
            abort_if($actor->two_factor_confirmed_at !== null, 409);
            $actor->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
            $actor->tokens()->delete();
        });
        $request->user()->refresh();
        $request->session()->forget(['organisation_invitation_mfa_secret', 'organisation_invitation_mfa_user']);
        $request->session()->put('google2fa', ['auth_passed' => true, 'auth_time' => now()]);
        $request->session()->regenerate(true);

        return redirect()->route('organisation-invitations.show', ['invitation' => $invitation->id])->with('status', 'Two-factor authentication is ready. You can now accept your invitation.');
    }

    public function complete(): Response
    {
        return response()->view('auth.organisation-invitation', ['organisationName' => null, 'invitationId' => null, 'token' => null]);
    }

    public function resend(ManageOrganisationInvitationRequest $request, OrganisationInvitation $invitation): RedirectResponse
    {
        $this->invitations->resend($invitation, $request->user());

        return back()->with('success', 'Invitation queued.');
    }

    public function revoke(ManageOrganisationInvitationRequest $request, OrganisationInvitation $invitation): RedirectResponse
    {
        $this->invitations->revoke($invitation, $request->user());

        return back()->with('success', 'Invitation revoked.');
    }

    private function postedInvitation(OrganisationInvitationTokenRequest $request, OrganisationInvitation $invitation): OrganisationInvitation
    {
        $current = $this->invitations->pendingToken($request->validated('token'));
        abort_unless($current->id === $invitation->id, 404);

        return $current;
    }

    private function remember(Request $request, OrganisationInvitation $invitation, #[SensitiveParameter] string $token): void
    {
        $request->session()->put('organisation_invitation', ['id' => $invitation->id, 'token' => Crypt::encryptString($token)]);
    }

    private function assertInviteIdentity(OrganisationInvitation $invitation, User $actor): void
    {
        abort_unless($actor->email_verified_at && mb_strtolower($actor->email) === $invitation->email && in_array($actor->status, [null, User::STATUS_ACTIVE], true), 403);
        abort_if($invitation->invited_user_id !== null && ((string) $invitation->invited_user_id !== (string) $actor->id || (int) $invitation->invited_security_version !== (int) $actor->security_version), 403);
    }
}

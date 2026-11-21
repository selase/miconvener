<?php

declare(strict_types=1);

use App\Http\Middleware\StaffAccountSecurity;
use App\Mail\Users\AccountEmailChanged;
use App\Mail\Users\VerifyAccountEmailChange;
use App\Models\AccountEmailChange;
use App\Models\OrganisationInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    Mail::fake();
    $this->identity = User::factory()->create(['email' => 'original@example.org', 'password' => Hash::make('current-password'), 'status' => User::STATUS_ACTIVE])->fresh();
    $this->actingAs($this->identity);
    $this->credentials = ['password' => 'current-password'];
});

/** @return array{0: AccountEmailChange, 1: string} */
function emailChangeLink(object $test, string $email = 'new@example.org'): array
{
    $test->postJson('/account-identity/email-changes', $test->credentials + ['email' => $email])->assertAccepted();
    $mail = Mail::queued(VerifyAccountEmailChange::class)->last();

    return [AccountEmailChange::query()->latest()->firstOrFail(), Crypt::decryptString($mail->encryptedToken)];
}

it('queues an encrypted one hour proof without changing the current identity', function (): void {
    [$change, $token] = emailChangeLink($this, ' NEW@Example.Org ');
    expect($change->current_email)->toBe('original@example.org')
        ->and($change->new_email)->toBe('new@example.org')
        ->and((string) $change->user_id)->toBe((string) $this->identity->id)
        ->and($change->security_version)->toBe(0)
        ->and($change->token_hash)->toBe(hash('sha256', $token))
        ->and($change->expires_at->timestamp - $change->created_at->timestamp)->toBe(3600)
        ->and($this->identity->fresh()->email)->toBe('original@example.org');
    $mail = Mail::queued(VerifyAccountEmailChange::class)->sole();
    expect($mail)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($mail->afterCommit)->toBeTrue()
        ->and(serialize($mail))->not->toContain($token)
        ->and($mail->content()->with['confirmationUrl'])->toEndWith('#'.$token);
    Mail::assertNotQueued(AccountEmailChanged::class);
});

it('requires the actual current password instead of an old session confirmation', function (): void {
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson('/account-identity/email-changes', ['email' => 'new@example.org', 'password' => 'wrong-password'])->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(AccountEmailChange::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('requires an enrolled authenticator code at request and again at confirmation', function (): void {
    $secret = app('pragmarx.google2fa')->generateSecretKey();
    $this->identity->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
    $this->withSession(['google2fa' => ['auth_passed' => true]]);
    $this->postJson('/account-identity/email-changes', $this->credentials + ['email' => 'new@example.org'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->credentials['code'] = app('pragmarx.google2fa')->getCurrentOtp($secret);
    [$change, $token] = emailChangeLink($this);
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", ['token' => $token, 'password' => 'current-password'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertSuccessful();
});

it('makes GET scanner safe and privately renders no token into the server page', function (): void {
    [$change, $token] = emailChangeLink($this);
    $this->get("/account-identity/email-changes/{$change->id}")->assertSuccessful()->assertSee('Confirm your new email')->assertDontSee($token)->assertDontSee('cdn.tailwindcss.com', false)->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($change->fresh()->consumed_at)->toBeNull()
        ->and($this->identity->fresh()->email)->toBe('original@example.org');
    Mail::assertNotQueued(AccountEmailChanged::class);
});

it('confirms once and rotates staff credentials while preserving memberships and unrelated portal proofs', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $this->identity->allTenants()->attach($tenant);
    $this->identity->createToken('personal');
    $resetToken = Password::createToken($this->identity);
    $oldIdentity = clone $this->identity;
    $oldRemember = $this->identity->remember_token;
    [$change, $token] = emailChangeLink($this);
    $oldSession = session()->getId();
    $this->withSession(['portal_unrelated' => ['email' => 'receipt@example.org']])->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertSuccessful()
        ->assertSessionHas('portal_unrelated.email', 'receipt@example.org')
        ->assertSessionHas(StaffAccountSecurity::SESSION_KEY, ['user_id' => (string) $this->identity->id, 'version' => 1]);
    $fresh = $this->identity->fresh();
    expect($fresh->email)->toBe('new@example.org')
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->security_version)->toBe(1)
        ->and($fresh->remember_token)->not->toBe($oldRemember)
        ->and($fresh->tokens()->count())->toBe(0)
        ->and($fresh->allTenants()->pluck('tenants.id')->all())->toBe([$tenant->id])
        ->and(Password::tokenExists($oldIdentity, $resetToken))->toBeFalse()
        ->and(session()->getId())->not->toBe($oldSession)
        ->and($change->fresh()->consumed_at)->not->toBeNull();
    Mail::assertQueued(AccountEmailChanged::class, fn ($mail): bool => $mail->hasTo('original@example.org') && ! str_contains(serialize($mail), $token));
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertStatus(410);
    Mail::assertQueuedCount(2);
});

it('rejects a different authenticated account even if it has the proof token', function (): void {
    [$change, $token] = emailChangeLink($this);
    $this->actingAs(User::factory()->create());
    $this->get("/account-identity/email-changes/{$change->id}")->assertForbidden();
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertForbidden();
});

it('rejects tampering expiry and revoked requests without changing email', function (string $invalid): void {
    [$change, $token] = emailChangeLink($this);
    if ($invalid === 'expiry') {
        $this->travel(1)->hours();
    } elseif ($invalid === 'superseded') {
        emailChangeLink($this, 'another@example.org');
    } else {
        $token = str_repeat('0', 64);
    }
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertStatus(410);
    expect($this->identity->fresh()->email)->toBe('original@example.org');
})->with(['expiry', 'superseded', 'tampered']);

it('binds the proof to current email and security epoch', function (string $changed): void {
    [$change, $token] = emailChangeLink($this);
    $this->identity->forceFill($changed === 'email' ? ['email' => 'edited@example.org'] : ['security_version' => 1])->save();
    $this->withSession([StaffAccountSecurity::SESSION_KEY => ['user_id' => (string) $this->identity->id, 'version' => (int) $this->identity->security_version]])->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertStatus(410);
})->with(['email', 'epoch']);

it('requires fresh password verification on confirmation', function (): void {
    [$change, $token] = emailChangeLink($this);
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", ['password' => 'wrong-password', 'token' => $token])->assertUnprocessable()->assertJsonValidationErrors('password');
    expect($change->fresh()->consumed_at)->toBeNull();
});

it('refuses canonical email collisions at request and at final redemption', function (): void {
    $existing = User::factory()->create(['email' => 'taken@example.org']);
    $this->postJson('/account-identity/email-changes', $this->credentials + ['email' => ' TAKEN@EXAMPLE.ORG '])->assertUnprocessable()->assertJsonValidationErrors('email');
    [$change, $token] = emailChangeLink($this);
    $existing->update(['email' => 'new@example.org']);
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($change->fresh()->consumed_at)->toBeNull();
});

it('serves an active identity with zero memberships despite stale workspace session', function (): void {
    $unrelated = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $this->withSession(['active_tenant_id' => $unrelated->id]);
    emailChangeLink($this);
});

it('does not let unverified inactive or impersonated sessions request an email change', function (string $state): void {
    if ($state === 'unverified') {
        $this->identity->forceFill(['email_verified_at' => null])->save();
    } elseif ($state === 'inactive') {
        $this->identity->update(['status' => User::STATUS_INACTIVE]);
    } else {
        $this->withSession(['original_user' => User::factory()->create()->id]);
    }
    $this->postJson('/account-identity/email-changes', $this->credentials + ['email' => 'new@example.org'])->assertStatus($state === 'inactive' ? 401 : 403);
    Mail::assertNothingQueued();
})->with(['unverified', 'inactive', 'impersonated']);

it('changes only the bound identity and leaves invitation cleanup to ownership bindings', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $role = Role::query()->create(['name' => 'Org Admin', 'guard_name' => 'web']);
    $invitation = OrganisationInvitation::query()->create(['tenant_id' => $tenant->id, 'email' => $this->identity->email, 'pending_email' => $this->identity->email, 'role_id' => $role->id, 'event_ids' => [], 'invited_by' => $this->identity->id, 'expires_at' => now()->addDay(), 'token_hash' => hash('sha256', 'original-proof'), 'status' => 'pending']);
    [$change, $token] = emailChangeLink($this);
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertSuccessful();
    expect($invitation->fresh()->email)->toBe('original@example.org')
        ->and($invitation->fresh()->status)->toBe('pending')
        ->and($this->identity->fresh()->email)->not->toBe($invitation->email)
        ->and($this->identity->allTenants()->count())->toBe(0);
});

it('accepts a same origin request from a valid tenant account and fixes mail links to the platform origin', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'email-change-team', 'isolation_mode' => 'shared']);
    $this->identity->allTenants()->attach($tenant);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $this->postJson("http://{$host}/account-identity/email-changes", $this->credentials + ['email' => 'new@example.org'], ['HTTP_HOST' => $host])->assertAccepted();
    $mail = Mail::queued(VerifyAccountEmailChange::class)->sole();
    expect($mail->content()->with['confirmationUrl'])->toStartWith(mb_rtrim((string) config('app.url'), '/').'/account-identity/email-changes/');
});

it('destroys the persisted old session while preserving unrelated portal proofs in the new session', function (): void {
    [$change, $token] = emailChangeLink($this);
    $guardKey = Illuminate\Support\Facades\Auth::guard('web')->getName();
    $oldSession = clone app('session.store');
    $oldSession->setId(Illuminate\Support\Str::random(40));
    $oldSession->replace([
        $guardKey => $this->identity->id,
        StaffAccountSecurity::SESSION_KEY => ['user_id' => (string) $this->identity->id, 'version' => 0],
        'portal_unrelated' => ['email' => 'receipt@example.org'],
    ]);
    $oldSession->save();
    $oldId = $oldSession->getId();
    Illuminate\Support\Facades\Auth::forgetGuards();
    $this->withCredentials()->withCookie(config('session.cookie'), $oldId)
        ->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])
        ->assertSuccessful()->assertSessionHas('portal_unrelated.email', 'receipt@example.org')
        ->assertSessionHas(StaffAccountSecurity::SESSION_KEY, ['user_id' => (string) $this->identity->id, 'version' => 1]);
    expect(app('session.store')->getHandler()->read($oldId))->toBe('');
    app('session.store')->flush();
    Illuminate\Support\Facades\Auth::forgetGuards();
    expect(Illuminate\Support\Facades\Auth::guard('web')->check())->toBeFalse();
    $this->withCookie(config('session.cookie'), $oldId)->get("/account-identity/email-changes/{$change->id}")->assertRedirect(route('login'));
});

it('claims the old mailbox security notification once without retrying uncertain transport', function (): void {
    [$change, $token] = emailChangeLink($this);
    $this->postJson("/account-identity/email-changes/{$change->id}/confirm", $this->credentials + ['token' => $token])->assertSuccessful();
    $mailer = (new Illuminate\Mail\MailManager(app()))->mailer('array');
    $mail = new AccountEmailChanged($change->id);
    $mail->to('original@example.org');
    $mail->send($mailer);
    expect($change->fresh()->notification_status)->toBe('sent');
    $sentAt = $change->fresh()->notified_at;
    expect($mail->send($mailer))->toBeNull();
    expect($change->fresh()->notified_at->equalTo($sentAt))->toBeTrue();
    $change->forceFill(['notification_status' => 'uncertain'])->save();
    expect($mail->send($mailer))->toBeNull();
});

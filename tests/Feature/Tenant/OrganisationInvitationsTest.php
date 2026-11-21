<?php

declare(strict_types=1);

use App\Mail\Users\OrganisationInvitationMail;
use App\Models\OrganisationInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\OrganisationInvitations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    refreshTenantDatabases();
    expect(config('database.connections.tenant.database'))->toBe(config('database.connections.landlord.database'));
    $this->freezeTime();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->realMailer = new Illuminate\Mail\MailManager(app());
    Mail::fake();
    RateLimiter::clear('organisation-invitations:application');
    $this->tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $this->owner = User::factory()->create();
    $this->tenant->users()->attach($this->owner->id);
    setPermissionsTeamId($this->tenant->id);
    $this->owner->assignRole('Org Superadmin');
    $this->role = Role::query()->whereNull('tenant_id')->where('name', 'Org Admin')->firstOrFail();
    $this->invites = app(OrganisationInvitations::class);
});

function organisationInvitationToken(OrganisationInvitation $invite): string
{
    return Crypt::decryptString($invite->delivery_payload);
}

test('invitations reserve without creating identity and persist only encrypted delivery material', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, ' New@Example.com ', $this->role, []);
    $token = organisationInvitationToken($invite);
    expect($invite->email)->toBe('new@example.com')
        ->and($invite->token_hash)->toBe(hash('sha256', $token))
        ->and($invite->status)->toBe('pending')
        ->and($invite->expires_at->getTimestamp())->toBe(now()->addDays(7)->getTimestamp())
        ->and(User::query()->where('email', $invite->email)->exists())->toBeFalse()
        ->and(json_encode($invite))->not->toContain($token);
});

test('duplicate case-normalized pending invitations reuse the reservation', function (): void {
    $first = $this->invites->invite($this->tenant, $this->owner, 'same@example.com', $this->role, []);
    $second = $this->invites->invite($this->tenant, $this->owner, 'SAME@example.com', $this->role, []);
    expect($second->id)->toBe($first->id)->and(OrganisationInvitation::query()->count())->toBe(1);
});

test('verified matching identities accept once without changing credentials', function (): void {
    $user = User::factory()->create(['email' => 'person@example.com']);
    $password = $user->password;
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    $token = organisationInvitationToken($invite);
    $membership = $this->invites->accept($token, $user);
    expect($membership->status)->toBe('active')->and($user->fresh()->password)->toBe($password)
        ->and($invite->fresh()->status)->toBe('accepted')->and($invite->fresh()->delivery_payload)->toBeNull();
    expect(fn () => $this->invites->accept($token, $user))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('wrong unverified and globally suspended identities cannot accept', function (string $kind): void {
    $user = User::factory()->create(['email' => $kind === 'wrong' ? 'wrong@example.com' : 'person@example.com', 'email_verified_at' => $kind === 'unverified' ? null : now(), 'status' => $kind === 'suspended' ? 'inactive' : 'active']);
    $invite = $this->invites->invite($this->tenant, $this->owner, 'person@example.com', $this->role, []);
    expect(fn () => $this->invites->accept(organisationInvitationToken($invite), $user))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($invite->fresh()->status)->toBe('pending');
})->with(['wrong', 'unverified', 'suspended']);

test('expired revoked and rotated tokens cannot accept', function (string $kind): void {
    $user = User::factory()->create();
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    $token = organisationInvitationToken($invite);
    if ($kind === 'expired') {
        $this->travel(8)->days();
    } elseif ($kind === 'revoked') {
        $this->invites->revoke($invite, $this->owner);
    } else {
        $this->invites->resend($invite, $this->owner);
    }
    expect(fn () => $this->invites->accept($token, $user))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
})->with(['expired', 'revoked', 'rotated']);

test('acceptance rechecks inviter authority', function (): void {
    $user = User::factory()->create();
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    TenantMembership::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->owner->id)->update(['status' => 'revoked']);
    expect(fn () => $this->invites->accept(organisationInvitationToken($invite), $user))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('scanner GET grants no membership or authentication and sends privacy headers', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'scan@example.com', $this->role, []);
    $this->get(route('organisation-invitations.show', ['invitation' => $invite->id]))
        ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    $this->assertGuest();
    expect($invite->fresh()->status)->toBe('pending')->and($this->tenant->users()->count())->toBe(1);
});

test('invite signup creates only the invited identity and membership', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'signup@example.com', $this->role, []);
    $count = Tenant::query()->count();
    $this->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite),
        'first_name' => 'New', 'last_name' => 'Person', 'password' => 'Safe!Password123456', 'password_confirmation' => 'Safe!Password123456',
    ])->assertRedirect();
    expect(Tenant::query()->count())->toBe($count)->and(User::query()->where('email', 'signup@example.com')->firstOrFail()->email_verified_at)->not->toBeNull()
        ->and($invite->fresh()->status)->toBe('accepted');
});

test('preclaimed accounts cannot have their password reset through invitation signup', function (): void {
    $user = User::factory()->unverified()->create();
    $password = $user->password;
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    $this->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite),
        'first_name' => 'New', 'last_name' => 'Person', 'password' => 'Safe!Password123456', 'password_confirmation' => 'Safe!Password123456',
    ])->assertRedirect(route('login'));
    expect($user->fresh()->password)->toBe($password)->and($invite->fresh()->status)->toBe('pending');
});

test('resending preserves the original deadline and revocation frees its reservation', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'resend@example.com', $this->role, []);
    $expires = $invite->expires_at;
    $this->travel(1)->days();
    $this->invites->resend($invite, $this->owner);
    expect($invite->fresh()->expires_at->equalTo($expires))->toBeTrue();
    $this->invites->revoke($invite, $this->owner);
    expect($invite->fresh()->status)->toBe('revoked')->and($invite->fresh()->pending_email)->toBeNull();
});

test('two factor required signup retains a verified identity without granting membership', function (): void {
    $this->tenant->update(['require_2fa' => true]);
    $invite = $this->invites->invite($this->tenant, $this->owner, 'mfa@example.com', $this->role, []);
    $token = organisationInvitationToken($invite);
    $this->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite),
        'first_name' => 'Mfa', 'last_name' => 'Person', 'password' => 'Safe!Password123456', 'password_confirmation' => 'Safe!Password123456',
    ])->assertRedirect(route('organisation-invitations.show', ['invitation' => $invite->id]));
    $user = User::query()->where('email', 'mfa@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull()->and($this->tenant->users()->whereKey($user->id)->exists())->toBeFalse()
        ->and($invite->fresh()->status)->toBe('pending');
    $this->post(route('organisation-invitations.mfa.setup', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite)])->assertRedirect();
    $secret = session('organisation_invitation_mfa_secret');
    $code = app('pragmarx.google2fa')->getCurrentOtp($secret);
    $this->post(route('organisation-invitations.mfa.confirm', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite), 'code' => $code])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite)])->assertRedirect();
    expect($invite->fresh()->status)->toBe('accepted');
});

test('a suspended member consumes a seat and cannot be invited back', function (): void {
    $user = User::factory()->create();
    $this->tenant->users()->attach($user->id, ['status' => 'suspended']);
    expect(fn () => $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('revoked memberships can accept a new invitation with fresh grants', function (): void {
    $user = User::factory()->create();
    $this->tenant->users()->attach($user->id, ['status' => 'revoked']);
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    expect($this->invites->accept(organisationInvitationToken($invite), $user)->status)->toBe('active');
});

test('already active members keep their grants without another invitation mail', function (): void {
    $user = User::factory()->create();
    $this->tenant->users()->attach($user->id);
    $user->assignRole('Event Staff');
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    expect($invite->status)->toBe('accepted')->and($user->fresh()->hasRole('Event Staff'))->toBeTrue();
    Mail::assertNothingQueued();
});

test('foreign events and platform roles are refused', function (string $kind): void {
    $role = $kind === 'role' ? Role::query()->where('name', 'Superadmin')->firstOrFail() : $this->role;
    $ids = $kind === 'event' ? [App\Models\Event::factory()->create()->id] : [];
    expect(fn () => $this->invites->invite($this->tenant, $this->owner, 'foreign@example.com', $role, $ids))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
})->with(['role', 'event']);

test('role changes after invitation are revalidated before admission', function (): void {
    $user = User::factory()->create();
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    $invite->update(['role_id' => Role::query()->where('name', 'Superadmin')->firstOrFail()->id]);
    expect(fn () => $this->invites->accept(organisationInvitationToken($invite), $user))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('invitation mail carries only identifiers and waits for commit', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'mail@example.com', $this->role, []);
    Mail::assertQueued(OrganisationInvitationMail::class, function (OrganisationInvitationMail $mail) use ($invite): bool {
        expect($mail->afterCommit)->toBeTrue()->and(serialize($mail))->not->toContain(organisationInvitationToken($invite));

        return $mail->invitationId === $invite->id;
    });
});

test('tenant transaction rollback discards invitation and after commit delivery', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    Mail::swap(app('mail.manager'));
    DB::connection('landlord')->beginTransaction();
    $this->invites->invite($this->tenant, $this->owner, 'rollback@example.com', $this->role, []);
    DB::connection('landlord')->rollBack();
    expect(OrganisationInvitation::query()->where('email', 'rollback@example.com')->exists())->toBeFalse();
    Illuminate\Support\Facades\Queue::assertNothingPushed();
});

test('recipient throttling limits resends without changing deadline', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'limited@example.com', $this->role, []);
    $this->invites->resend($invite, $this->owner);
    $this->invites->resend($invite, $this->owner);
    expect(fn () => $this->invites->resend($invite, $this->owner))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('maintenance marks abandoned delivery uncertain and purges old secrets without replay', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'abandoned@example.com', $this->role, []);
    $invite->update(['delivery_status' => 'sending', 'delivery_claimed_at' => now()->subMinutes(20)]);
    $this->invites->maintain($this->tenant);
    expect($invite->fresh()->delivery_status)->toBe('uncertain');
    expect(fn () => $this->invites->resend($invite->fresh(), $this->owner))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->travel(31)->days();
    $this->invites->maintain($this->tenant);
    expect($invite->fresh()->delivery_payload)->toBeNull()->and($invite->fresh()->token_hash)->toBeNull()->and($invite->fresh()->status)->toBe('expired');
});

test('delivery retries send at most once locally', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'delivery@example.com', $this->role, []);
    $mailer = new Illuminate\Mail\MailManager(app());
    $mail = (new OrganisationInvitationMail($invite->id, $invite->revision))->to($invite->email);
    $mail->send($mailer);
    expect($invite->fresh()->delivery_status)->toBe('sent')->and($invite->fresh()->delivery_payload)->toBeNull();
    $mail->send($mailer);
    expect($invite->fresh()->delivery_status)->toBe('sent');
});

test('valid reservation accepts at full capacity including after a plan downgrade', function (): void {
    $this->tenant->features()->create(['feature_key' => 'team_seats', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 2]]);
    $user = User::factory()->create();
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    expect(fn () => $this->invites->invite($this->tenant, $this->owner, 'overflow@example.com', $this->role, []))->toThrow(Illuminate\Validation\ValidationException::class);
    $this->tenant->features()->where('feature_key', 'team_seats')->update(['meta' => json_encode(['type' => 'limit', 'value' => 1])]);
    expect($this->invites->accept(organisationInvitationToken($invite), $user)->status)->toBe('active');
});

test('expired reservations free seats immediately and may be replaced', function (): void {
    $this->tenant->features()->create(['feature_key' => 'team_seats', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 2]]);
    $invite = $this->invites->invite($this->tenant, $this->owner, 'expiry@example.com', $this->role, []);
    $this->travel(8)->days();
    $replacement = $this->invites->invite($this->tenant, $this->owner, 'EXPIRY@example.com', $this->role, []);
    expect($replacement->id)->not->toBe($invite->id)->and($invite->fresh()->status)->toBe('expired');
});

test('wrong signed in account is denied by both scanner page and acceptance', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'invited@example.com', $this->role, []);
    $this->actingAs($this->owner)->get(route('organisation-invitations.show', ['invitation' => $invite->id]))->assertOk()->assertSee('Sign in with the invited account');
    $this->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite)])->assertForbidden();
    $this->assertAuthenticatedAs($this->owner);
});

test('resend and revoke HTTP endpoints reject foreign actors', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'managed@example.com', $this->role, []);
    $this->actingAs(User::factory()->create())->post(route('organisation-invitations.resend', $invite))->assertForbidden();
    $this->delete(route('organisation-invitations.revoke', $invite))->assertForbidden();
    expect($invite->fresh()->status)->toBe('pending');
});

test('transport ambiguity is recorded and never automatically replayed', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'uncertain@example.com', $this->role, []);
    $mailer = Mockery::mock(Illuminate\Contracts\Mail\Mailer::class);
    $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('transport interrupted'));
    $mail = (new OrganisationInvitationMail($invite->id, $invite->revision))->to($invite->email);
    $mail->send($mailer);
    $mail->send($mailer);
    expect($invite->fresh()->delivery_status)->toBe('uncertain')->and($invite->fresh()->delivery_payload)->toBeNull();
});

test('invitation sender organisation and IP budgets are enforced', function (string $scope, int $maximum): void {
    $key = match ($scope) {
        'sender' => 'sender:'.$this->owner->id,
        'tenant' => 'tenant:'.$this->tenant->id,
        'ip' => 'ip:'.request()->ip(),
    };
    for ($i = 0; $i < $maximum; $i++) {
        RateLimiter::hit('organisation-invitations:'.$key, 3600);
    }
    expect(fn () => $this->invites->invite($this->tenant, $this->owner, 'budget@example.com', $this->role, []))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(OrganisationInvitation::query()->count())->toBe(0);
})->with([['sender', 20], ['tenant', 50], ['ip', 60]]);

test('event grants are accepted in the invited tenant and restore unrelated request context', function (): void {
    $other = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = App\Models\Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $role = Role::query()->where('name', 'Event Organiser')->whereNull('tenant_id')->firstOrFail();
    $user = User::factory()->create();
    app(App\Services\Tenancy\TenantContext::class)->setTenant($other);
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $role, [$event->id]);
    $this->invites->accept(organisationInvitationToken($invite), $user);
    expect(App\Models\EventStaffAssignment::query()->where('tenant_id', $this->tenant->id)->where('user_id', $user->id)->where('event_id', $event->id)->exists())->toBeTrue()
        ->and(app(App\Services\Tenancy\TenantContext::class)->getTenant()->id)->toBe($other->id);
});

test('invitation pages suppress token and MFA material from Telescope recording', function (): void {
    app()->register(App\Providers\TelescopeServiceProvider::class, true);
    $invite = $this->invites->invite($this->tenant, $this->owner, 'telemetry@example.com', $this->role, []);
    $token = organisationInvitationToken($invite);
    $this->get(route('organisation-invitations.show', ['invitation' => $invite->id]))->assertOk();
    Laravel\Telescope\Telescope::$entriesQueue = [];
    Laravel\Telescope\Telescope::startRecording(false);
    Laravel\Telescope\Telescope::recordRequest(Laravel\Telescope\IncomingEntry::make(['uri' => '/organisation-invitations/'.$token]));
    expect(Laravel\Telescope\Telescope::$entriesQueue)->toBeEmpty();
    Laravel\Telescope\Telescope::stopRecording();
});

test('landlord uniqueness refuses a second pending reservation for the same address', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'unique@example.com', $this->role, []);
    expect(fn () => DB::connection('landlord')->transaction(function () use ($invite): void {
        $duplicate = $invite->replicate();
        $duplicate->token_hash = hash('sha256', random_bytes(32));
        $duplicate->save();
    }))->toThrow(Illuminate\Database\QueryException::class);
});

test('maintenance command is dry by default and bounded to the requested organisation', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'command@example.com', $this->role, []);
    $this->travel(8)->days();
    $this->artisan('organisations:maintain-invitations', ['tenant' => $this->tenant->id])->assertSuccessful();
    expect($invite->fresh()->status)->toBe('pending');
    $this->artisan('organisations:maintain-invitations', ['tenant' => $this->tenant->id, '--apply' => true])->assertSuccessful();
    expect($invite->fresh()->status)->toBe('expired');
});

test('explicit confirmed refusal reconciliation permits a new token without replaying the old send', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'reconcile@example.com', $this->role, []);
    $oldToken = organisationInvitationToken($invite);
    $invite->update(['delivery_status' => 'uncertain', 'delivery_payload' => null]);
    $this->artisan('organisations:maintain-invitations', ['tenant' => $this->tenant->id, '--invitation' => $invite->id, '--outcome' => 'refused', '--apply' => true])->assertSuccessful();
    expect($invite->fresh()->delivery_status)->toBe('refused');
    $this->invites->resend($invite->fresh(), $this->owner);
    expect(organisationInvitationToken($invite->fresh()))->not->toBe($oldToken);
});

test('an invitation bound to an existing identity refuses a recycled email address', function (): void {
    $original = User::factory()->create(['email' => 'recycled@example.com']);
    $invite = $this->invites->invite($this->tenant, $this->owner, $original->email, $this->role, []);
    $original->forceFill(['email' => 'moved@example.com'])->save();
    $replacement = User::factory()->create(['email' => 'recycled@example.com']);
    expect(fn () => $this->invites->accept(organisationInvitationToken($invite), $replacement))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $this->invites->resend($invite->fresh(), $this->owner))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($invite->fresh()->invited_user_id)->toBe($original->id);
});

test('changing email away and back cannot revive an older invitation proof', function (): void {
    $user = User::factory()->create(['email' => 'version@example.com']);
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    $user->forceFill(['email' => 'away@example.com', 'security_version' => (int) $user->security_version + 1])->save();
    $user->forceFill(['email' => 'version@example.com', 'email_verified_at' => now(), 'security_version' => (int) $user->security_version + 1])->save();
    expect(fn () => $this->invites->accept(organisationInvitationToken($invite), $user))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->invites->resend($invite->fresh(), $this->owner);
    expect($this->invites->accept(organisationInvitationToken($invite->fresh()), $user)->status)->toBe('active');
});

test('invite MFA enrolment revokes previously minted personal tokens without invalidating the invitation version', function (): void {
    $user = User::factory()->create();
    $version = (int) $user->security_version;
    $user->createToken('old-mobile');
    $this->tenant->update(['require_2fa' => true]);
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    $token = organisationInvitationToken($invite);
    $this->actingAs($user)->post(route('organisation-invitations.mfa.setup', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite)])->assertRedirect();
    $secret = session('organisation_invitation_mfa_secret');
    $code = app('pragmarx.google2fa')->getCurrentOtp($secret);
    $this->post(route('organisation-invitations.mfa.confirm', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite), 'code' => $code])->assertRedirect()->assertSessionHasNoErrors();
    expect($user->tokens()->count())->toBe(0)->and($user->fresh()->security_version)->toBe($version);
    $this->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => $token ?? organisationInvitationToken($invite)])->assertRedirect();
    expect($invite->fresh()->status)->toBe('accepted');
});

test('daily maintenance dispatches explicit tenant jobs and is scheduled', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $invite = $this->invites->invite($this->tenant, $this->owner, 'scheduled@example.com', $this->role, []);
    (new App\Jobs\Tenancy\DispatchOrganisationInvitationMaintenance)->handle();
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\Tenancy\MaintainOrganisationInvitations::class, fn ($job): bool => $job->tenantId === $this->tenant->id && ! str_contains(serialize($job), organisationInvitationToken($invite)));
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events());
    expect($events->contains(fn ($event): bool => $event->description === 'organisations:maintain-invitations' && $event->expression === '0 0 * * *'))->toBeTrue();
});

test('maintenance processes bounded rows and the tenant job queues a continuation', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $invite = $this->invites->invite($this->tenant, $this->owner, 'bounded@example.com', $this->role, []);
    for ($i = 0; $i < 100; $i++) {
        $copy = $invite->replicate();
        $copy->email = 'bounded-'.$i.'@example.com';
        $copy->pending_email = $copy->email;
        $copy->token_hash = hash('sha256', random_bytes(32));
        $copy->save();
    }
    $this->travel(8)->days();
    (new App\Jobs\Tenancy\MaintainOrganisationInvitations($this->tenant->id))->handle($this->invites);
    expect(OrganisationInvitation::query()->where('status', 'expired')->count())->toBe(100)
        ->and(OrganisationInvitation::query()->where('status', 'pending')->count())->toBe(1);
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\Tenancy\MaintainOrganisationInvitations::class, fn ($job): bool => $job->tenantId === $this->tenant->id);
});

test('acceptance uses the local stylesheet without remote scripts and protects the token page', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'styled@example.com', $this->role, []);
    $response = $this->get(route('organisation-invitations.show', ['invitation' => $invite->id]));
    $response->assertOk()->assertSee('max-w-lg')->assertSee('bg-canvas')->assertDontSee('cdn.tailwindcss.com')->assertDontSee('fonts.googleapis.com')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->getContent())->toContain('rel="stylesheet"');
});

test('an invited organiser with no selected events receives no event assignments', function (): void {
    $role = Role::query()->whereNull('tenant_id')->where('name', 'Event Organiser')->firstOrFail();
    $user = User::factory()->create();
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $role, []);
    $this->invites->accept(organisationInvitationToken($invite), $user);
    expect(App\Models\EventStaffAssignment::query()->where('tenant_id', $this->tenant->id)->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->hasRole('Event Organiser'))->toBeTrue();
});

test('email fragments keep grant tokens out of paths and scanner GET stores no proof', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'fragment@example.com', $this->role, []);
    $token = organisationInvitationToken($invite);
    $mail = new OrganisationInvitationMail($invite->id, $invite->revision);
    $url = $mail->content()->with['acceptUrl'];
    expect(parse_url($url, PHP_URL_PATH))->toBe('/organisation-invitations/'.$invite->id)
        ->and(parse_url($url, PHP_URL_FRAGMENT))->toBe($token)->and(parse_url($url, PHP_URL_QUERY))->toBeNull();
    $this->get('/organisation-invitations/'.$invite->id)->assertOk()->assertSessionMissing('organisation_invitation')->assertDontSee($token);
});

test('explicit sign in intent retains encrypted proof without requiring signup fields', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'signin@example.com', $this->role, []);
    $token = organisationInvitationToken($invite);
    $this->post('/organisation-invitations/'.$invite->id, ['token' => $token, 'intent' => 'signin'])->assertRedirect(route('login'))->assertSessionHasNoErrors();
    $pending = session('organisation_invitation');
    expect($pending['id'])->toBe($invite->id)->and($pending['token'])->not->toBe($token)
        ->and(Crypt::decryptString($pending['token']))->toBe($token);
});

test('disabled rollout refuses invitation creation and acceptance', function (): void {
    $user = User::factory()->create();
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    config(['organisation_access.enabled' => false]);
    expect(fn () => $this->invites->invite($this->tenant, $this->owner, 'disabled@example.com', $this->role, []))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $this->invites->accept(organisationInvitationToken($invite), $user))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->actingAs($user)->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => organisationInvitationToken($invite)])->assertStatus(503);
    expect($invite->fresh()->status)->toBe('pending');
});

test('malformed or mismatched body proof cannot grant access or be flashed in plaintext', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'malformed@example.com', $this->role, []);
    $this->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => 'malformed', 'intent' => 'signin'])->assertSessionHasErrors('token');
    expect(session('_old_input.token'))->toBeNull();
    $other = $this->invites->invite($this->tenant, $this->owner, 'another@example.com', $this->role, []);
    $this->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => organisationInvitationToken($other), 'intent' => 'signin'])->assertNotFound();
    expect($invite->fresh()->status)->toBe('pending')->and($other->fresh()->status)->toBe('pending');
});

test('sign in return and replay keep every redirect token free and acceptance explicit', function (): void {
    $user = User::factory()->create();
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    $token = organisationInvitationToken($invite);
    $url = route('organisation-invitations.accept', ['invitation' => $invite->id]);
    $this->post($url, ['token' => $token, 'intent' => 'signin'])->assertRedirect(route('login'));
    $this->actingAs($user)->get(route('organisation-invitations.resume'))->assertRedirect(route('organisation-invitations.show', ['invitation' => $invite->id]));
    $this->get(route('organisation-invitations.show', ['invitation' => $invite->id]))->assertOk()->assertSee('value="'.$token.'"', false);
    expect($invite->fresh()->status)->toBe('pending');
    $response = $this->post($url, ['token' => $token]);
    $response->assertRedirect(route('organisation-invitations.complete'));
    expect($response->headers->get('Location'))->not->toContain($token);
    $this->post($url, ['token' => $token])->assertNotFound();
});

test('wrong account switches only on explicit POST and preserves unrelated attendee proof', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'switch@example.com', $this->role, []);
    $token = organisationInvitationToken($invite);
    $proof = proofFor('attendee@example.com');
    $url = route('organisation-invitations.show', ['invitation' => $invite->id]);
    $this->actingAs($this->owner)->withSession($proof + ['google2fa' => ['auth_passed' => true], 'active_tenant_id' => $this->tenant->id])
        ->get($url)->assertOk()->assertSee('Sign in with the invited account');
    $this->assertAuthenticatedAs($this->owner);
    $this->post($url, ['token' => $token])->assertForbidden();
    $this->assertAuthenticatedAs($this->owner);
    $this->post($url, ['token' => $token, 'intent' => 'switch-account'])->assertRedirect(route('login'));
    $this->assertGuest();
    foreach ($proof as $key => $value) {
        expect(session($key))->toBe($value);
    }
    expect(session('google2fa'))->toBeNull()->and(session('active_tenant_id'))->toBeNull()
        ->and(session('organisation_invitation.id'))->toBe($invite->id)
        ->and(Crypt::decryptString(session('organisation_invitation.token')))->toBe($token)
        ->and($invite->fresh()->status)->toBe('pending');
});

test('authorised invitation management exposes Inertia success flash messages', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'flash@example.com', $this->role, []);
    $this->actingAs($this->owner)->post(route('organisation-invitations.resend', ['invitation' => $invite->id]))->assertRedirect()->assertSessionHas('success', 'Invitation queued.');
    $this->delete(route('organisation-invitations.revoke', ['invitation' => $invite->id]))->assertRedirect()->assertSessionHas('success', 'Invitation revoked.');
});

test('the authenticated completion page uses local styling without invitation proof', function (): void {
    $this->actingAs($this->owner)->get(route('organisation-invitations.complete'))->assertOk()->assertSee('Invitation accepted')->assertSee('bg-canvas')->assertHeader('Cache-Control', 'no-store, private');
});

test('authenticated malformed acceptance attempts still respect the source IP budget', function (): void {
    $invite = $this->invites->invite($this->tenant, $this->owner, 'attempts@example.com', $this->role, []);
    for ($i = 0; $i < 60; $i++) {
        RateLimiter::hit('organisation-invitations:public-ip:192.0.2.55', 3600);
    }
    $this->actingAs($this->owner)->withServerVariables(['REMOTE_ADDR' => '192.0.2.55'])
        ->post(route('organisation-invitations.accept', ['invitation' => $invite->id]), ['token' => 'malformed'])->assertStatus(429);
    expect($invite->fresh()->status)->toBe('pending');
});

test('invitation writes leave an existing tenant connection transaction object untouched', function (): void {
    $connection = DB::connection('tenant');
    $connection->beginTransaction();
    try {
        $this->invites->invite($this->tenant, $this->owner, 'connection@example.com', $this->role, []);
        expect(DB::connection('tenant'))->toBe($connection)->and($connection->transactionLevel())->toBe(1);
    } finally {
        $connection->rollBack();
    }
});

test('invalid MFA input never flashes plaintext token code or secret', function (): void {
    $user = User::factory()->create();
    $invite = $this->invites->invite($this->tenant, $this->owner, $user->email, $this->role, []);
    $token = organisationInvitationToken($invite);
    $this->actingAs($user)->post(route('organisation-invitations.mfa.confirm', ['invitation' => $invite->id]), ['token' => $token, 'code' => 'invalid-code', 'secret' => 'do-not-flash'])
        ->assertSessionHasErrors('code');
    expect(session('_old_input.token'))->toBeNull()->and(session('_old_input.code'))->toBeNull()->and(session('_old_input.secret'))->toBeNull()
        ->and(Crypt::decryptString(session('organisation_invitation.token')))->toBe($token);
});

test('a new-account invitation cannot enrol MFA after its request credentials were revoked', function (): void {
    $this->tenant->update(['require_2fa' => true]);
    $invitation = $this->invites->invite($this->tenant, $this->owner, 'fresh-mfa@example.test', $this->role, []);
    expect($invitation->invited_user_id)->toBeNull();
    $token = organisationInvitationToken($invitation);
    $actor = User::factory()->create(['email' => $invitation->email]);
    $authenticator = Mockery::mock();
    $authenticator->shouldReceive('verifyKey')->once()->andReturnUsing(function () use ($actor): bool {
        User::query()->whereKey($actor->id)->increment('security_version');

        return true;
    });
    app()->instance('pragmarx.google2fa', $authenticator);
    $this->actingAs($actor)->withSession([
        'organisation_invitation_mfa_secret' => 'B7S6S7S6S7S6S7S6',
        'organisation_invitation_mfa_user' => $actor->id,
    ])->postJson(route('organisation-invitations.mfa.confirm', ['invitation' => $invitation->id]), ['token' => $token, 'code' => '123456'])->assertUnauthorized();
    expect($actor->fresh()->two_factor_secret)->toBeNull();
});

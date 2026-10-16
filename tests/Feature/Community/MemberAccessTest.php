<?php

declare(strict_types=1);

use App\Models\CommunityAccessCredential;
use App\Models\Member;
use App\Services\Community\MemberAccess;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;

it('keeps exact email access without inferring any other identifier', function (): void {
    $a = Member::factory()->create(['email' => 'a@example.test', 'phone' => '+233241234567']);
    $b = Member::factory()->create(['email' => 'b@example.test', 'phone' => $a->phone]);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'A@EXAMPLE.TEST', 'expires_at' => now()->addHour()->timestamp]);
    expect(app(MemberAccess::class)->canView($session, $a))->toBeTrue()
        ->and(app(MemberAccess::class)->canView($session, $b))->toBeFalse()
        ->and(app(PhoneVerification::class)->verifiedPhone($session))->toBeNull();
});

it('does not expose family records through phone proof alone', function (): void {
    $member = Member::factory()->create(['phone' => '+233241234567']);
    $session = app('session.store');
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]);
    expect(app(MemberAccess::class)->canView($session, $member))->toBeFalse();
});

it('enrols a proven matching phone for the exact email-owned member only', function (): void {
    $a = Member::factory()->create(['email' => 'a@example.test', 'phone' => '+233241234567']);
    $b = Member::factory()->create(['email' => 'b@example.test', 'phone' => $a->phone]);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $a->email, 'expires_at' => now()->addHour()->timestamp]);
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => $a->phone, 'expires_at' => now()->addHour()->timestamp]);
    $access = app(MemberAccess::class);
    $credential = $access->enrolPhone($session, $a);
    $session->forget(PlatformAttendeeVerification::SESSION_KEY);
    expect($access->canView($session, $a))->toBeTrue()->and($access->canView($session, $b))->toBeFalse();
    $session->forget('community_member_grants');
    expect($access->canView($session, $a))->toBeFalse()
        ->and($access->establish($session, $credential['selector'], 'wrong'))->toBeFalse()
        ->and($access->establish($session, $credential['selector'], $credential['secret']))->toBeTrue();
    expect(CommunityAccessCredential::query()->firstOrFail()->toArray())->not->toHaveKeys(['secret_hash', 'phone_hash', 'phone']);
    $session->forget(PhoneVerification::SESSION_KEY);
    expect($access->establish($session, $credential['selector'], $credential['secret']))->toBeFalse()
        ->and($access->canView($session, $a))->toBeFalse();
});

it('rejects enrolment of an unproven or mismatched contact', function (): void {
    $member = Member::factory()->create(['phone' => '+233241234567']);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234568', 'expires_at' => now()->addHour()->timestamp]);
    expect(fn () => app(MemberAccess::class)->enrolPhone($session, $member))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('invalidates a recovered grant after credential rotation or member version change', function (): void {
    $member = Member::factory()->create(['phone' => '+233241234567']);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $access = app(MemberAccess::class);
    $old = $access->enrolPhone($session, $member);
    $new = $access->enrolPhone($session, $member);
    $session->forget(PlatformAttendeeVerification::SESSION_KEY);
    expect($access->establish($session, $old['selector'], $old['secret']))->toBeFalse()
        ->and($access->establish($session, $new['selector'], $new['secret']))->toBeTrue();
    $member->increment('access_version');
    expect($access->canView($session, $member->fresh()))->toBeFalse();
});

it('recovers access through HTTP without returning another copy of the credential', function (): void {
    $tenant = App\Models\Tenant::factory()->create();
    $tenant->features()->create(['feature_key' => 'community', 'enabled' => true]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+233241234567']);
    $host = mb_ltrim((string) config('session.domain'), '.');
    $proofs = [PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp],
        PhoneVerification::SESSION_KEY => ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]];
    $credential = $this->withServerVariables(['HTTP_HOST' => $host])->withSession($proofs)
        ->postJson('/my/communities/'.$tenant->slug.'/members/'.$member->id.'/phone-access')->assertOk()->json();
    $this->withSession(['community_member_grants' => []])->postJson('/my/communities/'.$tenant->slug.'/access', $credential)
        ->assertOk()->assertJsonPath('established', true)->assertJsonMissingPath('secret');
    $this->postJson('/my/communities/'.$tenant->slug.'/access', ['selector' => $credential['selector'], 'secret' => str_repeat('b', 64)])
        ->assertUnprocessable();
});

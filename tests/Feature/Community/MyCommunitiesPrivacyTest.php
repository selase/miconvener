<?php

declare(strict_types=1);
use App\Services\Community\MemberAccess;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;

require_once dirname(__DIR__, 2).'/Fixtures/community/portal-test-setup.php';
beforeEach(fn () => setupCommunityPortalTest($this));
it('never opens relatives or foreign members through a phone match or forged IDs', function (): void {
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $this->withSession($this->session->all())->getJson($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->relative->id)->assertNotFound();
    $this->getJson($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->foreign->id)->assertNotFound();
    $this->getJson($this->base.'/communities/'.$this->other->slug)->assertNotFound();
});
it('honours per-member grants and their immediate revocation on every private endpoint', function (): void {
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $credential = app(MemberAccess::class)->enrolPhone($this->session, $this->member);
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $url = $this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id;
    $this->withSession($this->session->all())->getJson($url)->assertOk()->assertJsonPath('member.access_role', 'self');
    App\Models\CommunityAccessCredential::findOrFail($credential['selector'])->update(['revoked_at' => now()]);
    $this->getJson($url)->assertNotFound();
    $this->getJson($url.'/charges')->assertNotFound();
    $this->getJson($this->base.'/communities')->assertOk()->assertJsonCount(0, 'communities.data');
});
it('does not use a still-live phone proof to replace expired email history or open Events', function (): void {
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'a@example.test', 'expires_at' => now()->subMinute()->timestamp]);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $this->withSession($this->session->all())->getJson($this->base.'/communities')->assertOk()->assertJsonCount(0, 'communities.data');
    $this->getJson($this->base.'/events')->assertUnauthorized();
});
it('does not accept pay links as portal credentials', function (): void {
    $this->session->flush();
    $this->getJson($this->base.'/communities?token='.$this->member->pay_token)->assertUnauthorized();
});

it('labels separately approved representatives without exposing the members other contacts', function (): void {
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234568', 'expires_at' => now()->addHour()->timestamp]);
    app(MemberAccess::class)->issue($this->session, $this->member, '+233241234568', 'representative', null, 'independent_review');
    $this->withSession($this->session->all())->getJson($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id)->assertOk()->assertJsonPath('member.access_role', 'representative')->assertDontSee($this->member->phone)->assertDontSee($this->member->email);
    $this->getJson($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->relative->id)->assertNotFound();
});

it('restores an existing credential with fresh phone proof even when new Community enrollment is paused', function (): void {
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $credential = app(MemberAccess::class)->enrolPhone($this->session, $this->member);
    $this->session->forget([PlatformAttendeeVerification::SESSION_KEY, 'community_member_grants']);
    expect($this->tenant->communityEnabled())->toBeFalse();
    $this->withSession($this->session->all())->postJson($this->base.'/communities/'.$this->tenant->slug.'/access', $credential)->assertOk()->assertJsonPath('established', true);
    $this->getJson($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id)->assertOk();
});

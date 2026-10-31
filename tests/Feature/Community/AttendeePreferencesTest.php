<?php

declare(strict_types=1);

use App\Services\Community\MessagingPreferences;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Events\PlatformAttendeeWorkspaceAuthorizer;

require_once dirname(__DIR__, 2).'/Fixtures/community/portal-test-setup.php';
beforeEach(fn () => setupCommunityPortalTest($this));
it('only changes announcements for an independently verified channel and authorised organisation', function (): void {
    $this->withSession($this->session->all())->getJson($this->base.'/preferences')->assertOk()->assertJsonCount(1, 'preferences.data')->assertJsonMissingPath('preferences.data.0.channels.sms');
    $body = ['channel' => 'email', 'marketing_enabled' => false, 'address' => 'b@example.test'];
    $this->patchJson($this->base.'/preferences/'.$this->tenant->slug, $body)->assertUnprocessable();
    unset($body['address']);
    $this->patchJson($this->base.'/preferences/'.$this->other->slug, $body)->assertNotFound();
    $this->patchJson($this->base.'/preferences/'.$this->tenant->slug, [...$body, 'channel' => 'sms'])->assertForbidden();
    $this->patchJson($this->base.'/preferences/'.$this->tenant->slug, $body)->assertOk();
    expect(app(MessagingPreferences::class)->allowsMarketing($this->tenant, 'email', 'a@example.test'))->toBeFalse()
        ->and(app(MessagingPreferences::class)->allowsMarketing($this->tenant, 'email', 'b@example.test'))->toBeTrue();
    $this->getJson($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id)->assertOk();
    $this->patchJson($this->base.'/preferences/'.$this->tenant->slug, ['channel' => 'email', 'marketing_enabled' => true, 'resume_marketing' => false])->assertUnprocessable();
});
it('removes only email proof but revokes checkout capabilities when email is removed', function (): void {
    $proof = [...$this->session->all(), PhoneVerification::SESSION_KEY => ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp], PlatformAttendeeWorkspaceAuthorizer::CHECKOUT_GRANTS_SESSION_KEY => ['one' => now()->addHour()->timestamp]];
    $this->withSession($proof)->deleteJson($this->base.'/identity/email')->assertOk()->assertSessionMissing(PlatformAttendeeVerification::SESSION_KEY)->assertSessionMissing(PlatformAttendeeWorkspaceAuthorizer::CHECKOUT_GRANTS_SESSION_KEY)->assertSessionHas(PhoneVerification::SESSION_KEY);
});
it('shows the actual member announcement permission and permits explicit resume with Following off', function (): void {
    $this->withSession($this->session->all())->getJson($this->base.'/preferences')->assertOk()->assertJsonPath('preferences.data.0.channels.email.marketing_enabled', true);
    $url = $this->base.'/preferences/'.$this->tenant->slug;
    $this->patchJson($url, ['channel' => 'email', 'marketing_enabled' => false])->assertOk();
    $this->patchJson($url, ['channel' => 'email', 'marketing_enabled' => true, 'resume_marketing' => true])->assertOk();
    expect(app(MessagingPreferences::class)->allowsMarketing($this->tenant, 'email', 'a@example.test'))->toBeTrue();
    expect(App\Models\CommunityFollower::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});
it('never resumes held SMS announcements through member preferences with Following off', function (): void {
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    app(App\Services\Community\WrongNumberReports::class)->report($this->tenant, $this->session, 'Number is reassigned.');
    App\Models\CommunityWrongNumberReport::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['status' => 'confirmed']);
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'sms', $this->member->phone);
    $this->withSession($this->session->all())->patchJson($this->base.'/preferences/'.$this->tenant->slug, ['channel' => 'sms', 'marketing_enabled' => true, 'resume_marketing' => true])->assertUnprocessable();
    expect(app(MessagingPreferences::class)->allowsMarketing($this->tenant, 'sms', $this->member->phone))->toBeFalse();
});

it('resumes independently authorised member SMS with Following on without creating a phone follower', function (): void {
    $this->tenant->features()->create(['feature_key' => 'community_following', 'enabled' => true]);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'sms', $this->member->phone);
    $this->withSession($this->session->all())->patchJson($this->base.'/preferences/'.$this->tenant->slug, ['channel' => 'sms', 'marketing_enabled' => true, 'resume_marketing' => true])->assertOk();
    expect(App\Models\CommunityFollower::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

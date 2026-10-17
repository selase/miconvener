<?php

declare(strict_types=1);

use App\Models\CommunityFollower;
use App\Models\Tenant;
use App\Services\Community\CommunityHistory;
use App\Services\Community\Following;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->features()->create(['feature_key' => 'community_following', 'enabled' => true]);
    $this->proof = app('session.store');
    $this->proof->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $this->following = app(Following::class);
});

it('refuses SMS-only follows even when a phone is independently verified', function (): void {
    expect(fn () => $this->following->follow($this->tenant, $this->proof, 'sms', true))->toThrow(AuthorizationException::class);
    expect(CommunityFollower::query()->count())->toBe(0);
});

it('requires an existing email follow before adding SMS', function (): void {
    $this->proof->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'owner@example.test', 'expires_at' => now()->addHour()->timestamp]);
    expect(fn () => $this->following->follow($this->tenant, $this->proof, 'sms', true))->toThrow(AuthorizationException::class);
});

it('attaches SMS to one email follow and keeps the two channel delivery choices', function (): void {
    $this->proof->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'owner@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $parent = $this->following->follow($this->tenant, $this->proof, 'email', true);
    $added = $this->following->follow($this->tenant, $this->proof, 'sms', true);
    expect($added->id)->toBe($parent->id)->and(CommunityFollower::query()->count())->toBe(1);
    expect($parent->channels()->firstOrFail()->address)->toBe('+233241234567');
    $fresh = $parent->fresh();
    $child = $fresh->channels()->firstOrFail();
    expect($child->consented_at->gte($fresh->consented_at))->toBeTrue('Parent '.$fresh->consented_at->format('c.u').' child '.$child->consented_at->format('c.u'));
    $source = app(App\Services\Community\FollowerAudienceSource::class);
    expect(iterator_count($source->addresses($this->tenant, ['kind' => 'followers'], 'email')))->toBe(1)
        ->and(iterator_count($source->addresses($this->tenant, ['kind' => 'followers'], 'sms')))->toBe(1);
    $this->following->unfollow($this->tenant, $this->proof, 'sms');
    expect($this->following->isFollowing($this->tenant, $this->proof, 'email'))->toBeTrue()
        ->and($this->following->isFollowing($this->tenant, $this->proof, 'sms'))->toBeFalse();
});

it('email unfollow hides SMS and re-follow does not revive its old consent', function (): void {
    $this->proof->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'owner@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $this->following->follow($this->tenant, $this->proof, 'email', true);
    $this->following->follow($this->tenant, $this->proof, 'sms', true);
    $this->following->unfollow($this->tenant, $this->proof, 'email');
    expect($this->following->isFollowing($this->tenant, $this->proof, 'sms'))->toBeFalse();
    $this->following->follow($this->tenant, $this->proof, 'email', true);
    expect($this->following->isFollowing($this->tenant, $this->proof, 'sms'))->toBeFalse();
});

it('does not enumerate a legacy standalone phone follower by phone proof', function (): void {
    CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'channel' => 'sms', 'address' => '+233241234567', 'address_hash' => app(App\Services\Community\MessagingPreferences::class)->hash('sms', '+233241234567')]);
    expect(app(CommunityHistory::class)->communities($this->proof)['data'])->toBe([]);
});

it('keeps a same-second SMS consent after the email consent eligible', function (): void {
    $this->proof->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'owner@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-17 09:00:00.100000 UTC'));
    $parent = $this->following->follow($this->tenant, $this->proof, 'email', true);
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-17 09:00:00.200000 UTC'));
    $this->following->follow($this->tenant, $this->proof, 'sms', true);
    expect($parent->channels()->sole()->consented_at->format('u'))->toBe('200000')
        ->and($this->following->isFollowing($this->tenant, $this->proof, 'sms'))->toBeTrue();
});

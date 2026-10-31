<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\MemberAccess;
use App\Services\Community\PhoneVerification;
use App\Services\Events\MemberBenefitEligibility;
use App\Services\Events\PlatformAttendeeVerification;

function benefitOrganisation(): Tenant
{
    $tenant = Tenant::factory()->create();
    foreach (['community', 'community_event_benefits'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }

    return $tenant;
}

it('requires active personal membership but not dues or an active dues type', function (string $status, bool $allowed): void {
    $tenant = benefitOrganisation();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'self@example.test', 'status' => $status]);
    $member->membershipType->update(['amount' => null, 'period' => null, 'is_active' => false]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'SELF@example.test', 'expires_at' => now()->addHour()->timestamp]);
    expect(app(MemberBenefitEligibility::class)->resolve($session, $event, $member->email)?->id)->toBe($allowed ? $member->id : null);
})->with([['active', true], ['pending', false], ['lapsed', false], ['exited', false]]);

it('does not infer membership from another tenant, a typed email, phone proof or expired proof', function (string $proof): void {
    $tenant = benefitOrganisation();
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $session = app('session.store');
    if ($proof === 'phone') {
        $session->put(PhoneVerification::SESSION_KEY, ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]);
    } elseif ($proof !== 'none') {
        $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => $proof === 'expired' ? now()->subSecond()->timestamp : now()->addHour()->timestamp]);
    }
    if ($proof === 'other tenant') {
        $event = Event::factory()->create(['tenant_id' => benefitOrganisation()->id]);
    }
    expect(app(MemberBenefitEligibility::class)->resolve($session, $event, $member->email))->toBeNull();
})->with(['phone', 'none', 'expired', 'other tenant']);

it('binds a valid self grant to exactly its member and recorded registration email', function (string $role): void {
    $tenant = benefitOrganisation();
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+233241234567']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $session = app('session.store');
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]);
    app(MemberAccess::class)->issue($session, $member, $member->phone, $role, null, 'staff_review');
    $resolver = app(MemberBenefitEligibility::class);
    expect($resolver->resolve($session, $event, $member->email)?->id)->toBe($role === 'self' ? $member->id : null)
        ->and($resolver->resolve($session, $event, 'different@example.test'))->toBeNull();
    $member->increment('access_version');
    expect($resolver->resolve($session, $event, $member->email))->toBeNull();
})->with(['self', 'representative']);

it('fails closed when either rollout switch is off or email is missing', function (): void {
    $tenant = benefitOrganisation();
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    $resolver = app(MemberBenefitEligibility::class);
    expect($resolver->resolve($session, $event, ''))->toBeNull();
    foreach (['community', 'community_event_benefits'] as $key) {
        $tenant->features()->where('feature_key', $key)->update(['enabled' => false]);
        expect($resolver->resolve($session, $event, $member->email))->toBeNull();
        $tenant->features()->where('feature_key', $key)->update(['enabled' => true]);
    }
});

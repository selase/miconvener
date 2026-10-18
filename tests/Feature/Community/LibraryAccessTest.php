<?php

declare(strict_types=1);

use App\Models\CommunityAccessCredential;
use App\Models\CommunityGroup;
use App\Models\CommunityLibraryItem;
use App\Services\Community\CommunityContentAccess;
use App\Services\Community\MemberAccess;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;

require_once __DIR__.'/../../Support/CommunityLibraryScenario.php';

it('allows an active no-dues member without event registration or messaging consent', function () {
    [$tenant, $member, $item] = libraryReader();
    $access = app(CommunityContentAccess::class);
    expect($access->canRead(app('session.store'), $item))->toBeTrue()
        ->and($access->readers(app('session.store'), $tenant, ['kind' => 'all_members'])->modelKeys())->toBe([$member->id]);
});

it('denies pending lapsed and exited members and expired email proof', function (string $status) {
    [, $member, $item] = libraryReader();
    $member->update(['status' => $status]);
    expect(app(CommunityContentAccess::class)->canRead(app('session.store'), $item))->toBeFalse();
})->with(['pending', 'lapsed', 'exited']);

it('checks current publication release and identifier on every read', function () {
    [, $member, $item] = libraryReader();
    $access = app(CommunityContentAccess::class);
    foreach (['draft', 'withdrawn'] as $state) {
        $item->update(['state' => $state]);
        expect($access->canRead(app('session.store'), $item))->toBeFalse();
    }
    $item->update(['state' => 'published', 'release_at' => now()->addHour()]);
    expect($access->canRead(app('session.store'), $item))->toBeFalse();
    $item->update(['release_at' => null]);
    $member->update(['email' => 'changed@example.test']);
    expect($access->canRead(app('session.store'), $item))->toBeFalse();
});

it('does not reveal another organisation or infer family access from a phone', function () {
    [, $member, $item] = libraryReader();
    $foreign = CommunityLibraryItem::factory()->create();
    expect(app(CommunityContentAccess::class)->canRead(app('session.store'), $foreign))->toBeFalse();
    app('session.store')->forget(PlatformAttendeeVerification::SESSION_KEY);
    app('session.store')->put(PhoneVerification::SESSION_KEY, ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]);
    expect(app(CommunityContentAccess::class)->canRead(app('session.store'), $item))->toBeFalse();
});

it('allows an explicitly approved representative to read until revoked', function () {
    [, $member, $item] = libraryReader();
    app('session.store')->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $credential = app(MemberAccess::class)->issue(app('session.store'), $member, '+233241234567', 'representative', null, 'staff_review');
    app('session.store')->forget(PlatformAttendeeVerification::SESSION_KEY);
    expect(app(CommunityContentAccess::class)->canRead(app('session.store'), $item))->toBeTrue();
    CommunityAccessCredential::query()->whereKey($credential['selector'])->update(['revoked_at' => now()]);
    expect(app(CommunityContentAccess::class)->canRead(app('session.store'), $item))->toBeFalse();
});

it('requires a current active group and fails closed for unsupported audiences', function () {
    [$tenant, $member, $item] = libraryReader();
    $group = CommunityGroup::factory()->create(['tenant_id' => $tenant->id]);
    $item->update(['audience' => ['kind' => 'groups', 'group_ids' => [$group->id]]]);
    $access = app(CommunityContentAccess::class);
    expect($access->canRead(app('session.store'), $item))->toBeFalse();
    $member->groups()->attach($group->id, ['tenant_id' => $tenant->id]);
    expect($access->canRead(app('session.store'), $item))->toBeTrue();
    $group->update(['archived_at' => now()]);
    expect($access->canRead(app('session.store'), $item))->toBeFalse();
    $item->update(['audience' => ['kind' => 'members_and_followers']]);
    expect($access->canRead(app('session.store'), $item))->toBeFalse();
});

it('clears library access when the verified email proof expires', function () {
    [, , $item] = libraryReader();
    $this->travel(2)->hours();
    expect(app(CommunityContentAccess::class)->canRead(app('session.store'), $item))->toBeFalse();
});

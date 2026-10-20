<?php

declare(strict_types=1);

use App\Enum\MemberStatusEnum;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Package;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Venue\StaffIdentity;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;

/**
 * Staff are people an organisation has tagged Staff, or (once it has proved the
 * domain) people who verify an address there. It is the person's verified
 * identifier that counts, per organisation, and only on Enterprise.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
});

function organisationOn(string $plan, array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create($attributes + ['package_id' => Package::query()->where('slug', $plan)->value('id')]);
    $tenant->syncFeaturesFromPackage();

    return $tenant;
}

function verifiedAs(string $email): Session
{
    $session = new Store('test', new ArraySessionHandler(120));
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $email, 'verified_at' => now()->timestamp, 'expires_at' => now()->addHour()->timestamp]);

    return $session;
}

test('a member tagged staff is staff', function (): void {
    $tenant = organisationOn('enterprise');
    Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'ama@ugmc.example', 'is_staff' => true]);

    expect(app(StaffIdentity::class)->isStaff($tenant, verifiedAs('ama@ugmc.example')))->toBeTrue();
});

test('a member who is not tagged is not staff, and a stranger is not either', function (): void {
    $tenant = organisationOn('enterprise');
    Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'kofi@example.test', 'is_staff' => false]);

    expect(app(StaffIdentity::class)->isStaff($tenant, verifiedAs('kofi@example.test')))->toBeFalse()
        ->and(app(StaffIdentity::class)->isStaff($tenant, verifiedAs('nobody@example.test')))->toBeFalse();
});

test('a person with no verified identifier is not staff', function (): void {
    $tenant = organisationOn('enterprise');
    Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'ama@ugmc.example', 'is_staff' => true]);

    expect(app(StaffIdentity::class)->isStaff($tenant, new Store('test', new ArraySessionHandler(120))))->toBeFalse();
});

test('staff of one organisation are not staff of another', function (): void {
    $first = organisationOn('enterprise');
    $second = organisationOn('enterprise');
    Member::factory()->create(['tenant_id' => $first->id, 'email' => 'ama@ugmc.example', 'is_staff' => true]);

    expect(app(StaffIdentity::class)->isStaff($first, verifiedAs('ama@ugmc.example')))->toBeTrue()
        ->and(app(StaffIdentity::class)->isStaff($second, verifiedAs('ama@ugmc.example')))->toBeFalse();
});

test('an exited member is no longer staff', function (): void {
    $tenant = organisationOn('enterprise');
    Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'ama@ugmc.example', 'is_staff' => true, 'status' => MemberStatusEnum::Exited]);

    expect(app(StaffIdentity::class)->isStaff($tenant, verifiedAs('ama@ugmc.example')))->toBeFalse();
});

test('an organisation that is not on enterprise has no staff', function (): void {
    $tenant = organisationOn('growth');
    Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'ama@ugmc.example', 'is_staff' => true]);

    expect(app(StaffIdentity::class)->isStaff($tenant, verifiedAs('ama@ugmc.example')))->toBeFalse();
});

test('a verified-domain address is staff, and becomes a tagged member without dues on first use', function (): void {
    $tenant = organisationOn('enterprise', ['staff_email_domains' => [['domain' => 'ugmc.example', 'token' => 'x', 'verified_at' => now()->toIso8601String()]]]);
    $session = verifiedAs('yaw.boateng@ugmc.example');

    expect(app(StaffIdentity::class)->isStaff($tenant, $session))->toBeTrue()
        ->and(Member::query()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $member = app(StaffIdentity::class)->memberFor($tenant, $session);

    expect($member)->not->toBeNull()
        ->and($member->is_staff)->toBeTrue()
        ->and($member->email)->toBe('yaw.boateng@ugmc.example')
        ->and($member->status)->toBe(MemberStatusEnum::Active)
        ->and($member->membershipType->hasDues())->toBeFalse()
        ->and(app(StaffIdentity::class)->memberFor($tenant, $session)->id)->toBe($member->id)
        ->and(Member::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(MembershipType::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('an unverified domain, or a lookalike, grants nothing', function (): void {
    $unverified = organisationOn('enterprise', ['staff_email_domains' => [['domain' => 'ugmc.example', 'token' => 'x', 'verified_at' => null]]]);
    $verified = organisationOn('enterprise', ['staff_email_domains' => [['domain' => 'ugmc.example', 'token' => 'x', 'verified_at' => now()->toIso8601String()]]]);

    expect(app(StaffIdentity::class)->isStaff($unverified, verifiedAs('ama@ugmc.example')))->toBeFalse();

    foreach (['ama@evilugmc.example', 'ama@ugmc.example.evil.test', 'ama@mail.ugmc.example', 'ama@ugmc.example@evil.test'] as $lookalike) {
        expect(app(StaffIdentity::class)->isStaff($verified, verifiedAs($lookalike)))->toBeFalse();
    }
});

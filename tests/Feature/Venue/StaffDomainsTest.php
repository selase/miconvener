<?php

declare(strict_types=1);

use App\Contracts\DnsTxtResolver;
use App\Models\Member;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Venue\StaffDomains;
use App\Services\Venue\StaffIdentity;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

/**
 * An email domain makes its addresses staff only after the organisation proves
 * it owns the domain by publishing a record we give it. Until then it grants
 * nothing, and a domain proved by one organisation is never another's.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);

    $this->dns = new class implements DnsTxtResolver
    {
        /** @var array<string, list<string>> */
        public array $records = [];

        public function txt(string $name): array
        {
            return $this->records[$name] ?? [];
        }
    };
    app()->instance(DnsTxtResolver::class, $this->dns);

    $this->owner = User::factory()->create();
    $this->tenant = setActiveTenantForTest($this->owner, ['package_id' => Package::query()->where('slug', 'enterprise')->value('id')]);
    $this->tenant->syncFeaturesFromPackage();
    $this->owner->assignRole('Org Superadmin');
    $this->domains = app(StaffDomains::class);
});

function sessionFor(string $email): Store
{
    $session = new Store('t', new ArraySessionHandler(120));
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $email, 'verified_at' => now()->timestamp, 'expires_at' => now()->addHour()->timestamp]);

    return $session;
}

test('adding a domain gives the record to publish and grants nothing yet', function (): void {
    $record = $this->domains->add($this->tenant, 'UGMC.example');

    expect($record['name'])->toBe('_miconvener-staff.ugmc.example')
        ->and($record['value'])->toStartWith('miconvener-staff-verification=')
        ->and($this->tenant->fresh()->staff_email_domains[0]['verified_at'])->toBeNull()
        ->and($this->domains->matches($this->tenant->fresh(), 'ama@ugmc.example'))->toBeFalse();
});

test('a domain that is not a plain host name is refused', function (string $domain): void {
    expect(fn () => $this->domains->add($this->tenant, $domain))->toThrow(ValidationException::class);
})->with(['https://ugmc.example', 'ugmc', 'ugmc.example/path', 'ama@ugmc.example', '*.ugmc.example', 'ugmc example']);

test('verifying succeeds only when the published record matches', function (): void {
    $record = $this->domains->add($this->tenant, 'ugmc.example');

    expect($this->domains->verify($this->tenant->fresh(), 'ugmc.example'))->toBeFalse();

    $this->dns->records[$record['name']] = ['miconvener-staff-verification=wrong'];
    expect($this->domains->verify($this->tenant->fresh(), 'ugmc.example'))->toBeFalse();

    $this->dns->records[$record['name']] = ['v=spf1 -all', $record['value']];
    expect($this->domains->verify($this->tenant->fresh(), 'ugmc.example'))->toBeTrue()
        ->and($this->domains->matches($this->tenant->fresh(), 'ama@ugmc.example'))->toBeTrue();
});

test('a verified domain makes verified addresses there staff, and removing it stops that at once', function (): void {
    $record = $this->domains->add($this->tenant, 'ugmc.example');
    $this->dns->records[$record['name']] = [$record['value']];
    $this->domains->verify($this->tenant->fresh(), 'ugmc.example');

    expect(app(StaffIdentity::class)->isStaff($this->tenant->fresh(), sessionFor('ama@ugmc.example')))->toBeTrue();

    $this->domains->remove($this->tenant->fresh(), 'ugmc.example');

    expect(app(StaffIdentity::class)->isStaff($this->tenant->fresh(), sessionFor('ama@ugmc.example')))->toBeFalse();
});

test('a domain proved by another organisation cannot be added or verified', function (): void {
    $other = Tenant::factory()->create(['package_id' => Package::query()->where('slug', 'enterprise')->value('id')]);
    $other->syncFeaturesFromPackage();

    $mine = $this->domains->add($this->tenant, 'ugmc.example');
    $theirs = $this->domains->add($other, 'ugmc.example');
    $this->dns->records[$theirs['name']] = [$theirs['value']];
    expect($this->domains->verify($other->fresh(), 'ugmc.example'))->toBeTrue();

    $this->dns->records[$mine['name']] = [$mine['value']];
    expect($this->domains->verify($this->tenant->fresh(), 'ugmc.example'))->toBeFalse();
    expect(fn () => $this->domains->add(Tenant::factory()->create(), 'ugmc.example'))->toThrow(ValidationException::class);
});

test('adding the same domain twice keeps one entry and its token', function (): void {
    $first = $this->domains->add($this->tenant, 'ugmc.example');
    $second = $this->domains->add($this->tenant->fresh(), 'ugmc.example');

    expect($second)->toBe($first)->and($this->tenant->fresh()->staff_email_domains)->toHaveCount(1);
});

test('the console adds, verifies and removes a domain', function (): void {
    $route = fn (string $name, array $extra = []) => route("tenant.venue.internal-bookings.domains.{$name}", ['subdomain' => $this->tenant->slug] + $extra);

    $this->actingAs($this->owner)->post($route('store'), ['domain' => 'ugmc.example'])->assertSessionHasNoErrors();
    $entry = $this->tenant->fresh()->staff_email_domains[0];

    $this->actingAs($this->owner)->post($route('verify'), ['domain' => 'ugmc.example'])->assertSessionHas('error');

    $this->dns->records['_miconvener-staff.ugmc.example'] = ['miconvener-staff-verification='.$entry['token']];
    $this->actingAs($this->owner)->post($route('verify'), ['domain' => 'ugmc.example'])->assertSessionHas('success');
    expect($this->tenant->fresh()->staff_email_domains[0]['verified_at'])->not->toBeNull();

    $this->actingAs($this->owner)->delete($route('destroy'), ['domain' => 'ugmc.example'])->assertSessionHasNoErrors();
    expect($this->tenant->fresh()->staff_email_domains)->toBe([]);
});

test('the domain routes need the permission and the plan', function (): void {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($admin->id);
    setPermissionsTeamId($this->tenant->id);
    $admin->assignRole('Org Admin');
    $admin->revokePermissionTo('manage room bookings');
    $url = route('tenant.venue.internal-bookings.domains.store', ['subdomain' => $this->tenant->slug]);

    $this->actingAs($admin)->post($url, ['domain' => 'ugmc.example'])->assertForbidden();

    $this->tenant->update(['package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    $this->tenant->syncFeaturesFromPackage();
    $this->actingAs($this->owner)->post($url, ['domain' => 'ugmc.example'])->assertForbidden();
});

test('the staff list page shows the domains with their record and status', function (): void {
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'is_staff' => true]);
    $record = $this->domains->add($this->tenant, 'ugmc.example');

    $this->actingAs($this->owner)->get(route('tenant.venue.internal-bookings.index', ['subdomain' => $this->tenant->slug]))->assertInertia(fn ($page) => $page
        ->where('domains.0.domain', 'ugmc.example')
        ->where('domains.0.verified', false)
        ->where('domains.0.record_name', $record['name'])
        ->where('domains.0.record_value', $record['value']));
});

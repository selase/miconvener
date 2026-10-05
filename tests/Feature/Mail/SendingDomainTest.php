<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantSendingDomain;
use App\Models\User;
use App\Services\Mail\SendingDomainService;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Enterprise organisers can send event mail from their own domain, set up by a
 * superadmin (decided 2026-10-05, after Luma). SES issues three DKIM records,
 * the organiser publishes them, and a scheduled check switches the domain on,
 * and off again if the records go away.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->seed(EventPackageSeeder::class);

    $this->ses = new MockHandler;
    $this->sesCalls = [];
    app()->instance(SesV2Client::class, new SesV2Client([
        'version' => 'latest',
        'region' => 'eu-west-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $this->ses,
    ]));
});

function sesIdentity(bool $verified, string $dkimStatus = 'PENDING'): Result
{
    return new Result([
        'VerifiedForSendingStatus' => $verified,
        'DkimAttributes' => ['Status' => $dkimStatus, 'Tokens' => ['tok1', 'tok2', 'tok3']],
    ]);
}

function sesError(string $code): callable
{
    return fn (CommandInterface $command): SesV2Exception => new SesV2Exception($code, $command, ['code' => $code]);
}

function sendingDomainTenant(string $plan = 'enterprise'): Tenant
{
    return Tenant::factory()->create([
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', $plan)->firstOrFail()->id,
    ]);
}

function platformSuperadmin(): User
{
    $superadmin = User::factory()->create(['tenant_id' => null]);
    $superadmin->assignRole('Superadmin');

    return $superadmin;
}

test('registering a domain stores the three DKIM records the organiser must publish', function (): void {
    $tenant = sendingDomainTenant();
    $this->ses->append(sesIdentity(false));

    $this->actingAs(platformSuperadmin())
        ->post(route('tenants.sending-domain.store', $tenant->uuid), [
            'domain' => ' Events.TechSummit.gh ',
            'from_address' => 'hello@events.techsummit.gh',
        ])
        ->assertRedirect(route('tenants.sending-domain.show', $tenant->uuid));

    $sendingDomain = $tenant->fresh()->sendingDomain;

    expect($sendingDomain->domain)->toBe('events.techsummit.gh')
        ->and($sendingDomain->status)->toBe(TenantSendingDomain::STATUS_PENDING)
        ->and($sendingDomain->dkim_records)->toHaveCount(3)
        ->and($sendingDomain->dkim_records[0])->toBe([
            'name' => 'tok1._domainkey.events.techsummit.gh',
            'type' => 'CNAME',
            'value' => 'tok1.dkim.amazonses.com',
        ]);
});

test('a domain already registered with SES is picked up rather than refused', function (): void {
    $tenant = sendingDomainTenant();
    $this->ses->append(sesError('AlreadyExistsException'), sesIdentity(true, 'SUCCESS'));

    $sendingDomain = app(SendingDomainService::class)->start($tenant, 'events.techsummit.gh', 'hello@events.techsummit.gh');

    expect($sendingDomain->isVerified())->toBeTrue()
        ->and($sendingDomain->verified_at)->not->toBeNull();
});

test('only Enterprise organisations can have a sending domain', function (): void {
    $tenant = sendingDomainTenant('growth');

    $this->actingAs(platformSuperadmin())
        ->post(route('tenants.sending-domain.store', $tenant->uuid), [
            'domain' => 'events.techsummit.gh',
            'from_address' => 'hello@events.techsummit.gh',
        ])
        ->assertSessionHas('error');

    expect($tenant->fresh()->sendingDomain)->toBeNull()
        ->and($this->ses->count())->toBe(0);
});

test('the from address must be on the domain being registered', function (): void {
    $tenant = sendingDomainTenant();

    $this->actingAs(platformSuperadmin())
        ->post(route('tenants.sending-domain.store', $tenant->uuid), [
            'domain' => 'events.techsummit.gh',
            'from_address' => 'someone@gmail.com',
        ])
        ->assertSessionHasErrors('from_address');
});

test('organisation admins cannot reach the set-up', function (): void {
    $tenant = sendingDomainTenant();
    $admin = User::factory()->create();
    setActiveTenantForTest($admin);
    $admin->assignRole('Org Admin');

    $this->actingAs($admin)->get(route('tenants.sending-domain.show', $tenant->uuid))->assertForbidden();
    $this->actingAs($admin)
        ->post(route('tenants.sending-domain.store', $tenant->uuid), [
            'domain' => 'events.techsummit.gh',
            'from_address' => 'hello@events.techsummit.gh',
        ])
        ->assertForbidden();
});

test('the scheduled check switches a domain on when SES finds the records, and off when they go', function (): void {
    $sendingDomain = TenantSendingDomain::query()->create([
        'tenant_id' => sendingDomainTenant()->id,
        'domain' => 'events.techsummit.gh',
        'from_address' => 'hello@events.techsummit.gh',
        'status' => TenantSendingDomain::STATUS_PENDING,
    ]);

    $this->ses->append(sesIdentity(true, 'SUCCESS'));
    $this->artisan('mail:check-sending-domains')->assertSuccessful();
    expect($sendingDomain->fresh()->isVerified())->toBeTrue();

    $this->ses->append(sesIdentity(false, 'FAILED'));
    $this->artisan('mail:check-sending-domains')->assertSuccessful();
    expect($sendingDomain->fresh()->status)->toBe(TenantSendingDomain::STATUS_FAILED)
        ->and($sendingDomain->fresh()->verified_at)->toBeNull();
});

test('the check carries on past a domain SES cannot answer for', function (): void {
    $first = TenantSendingDomain::query()->create([
        'tenant_id' => sendingDomainTenant()->id,
        'domain' => 'a.example.com',
        'from_address' => 'hi@a.example.com',
    ]);
    $second = TenantSendingDomain::query()->create([
        'tenant_id' => sendingDomainTenant()->id,
        'domain' => 'b.example.com',
        'from_address' => 'hi@b.example.com',
    ]);

    $this->ses->append(sesError('TooManyRequestsException'), sesIdentity(true, 'SUCCESS'));
    $this->artisan('mail:check-sending-domains')->assertSuccessful();

    expect(collect([$first->fresh(), $second->fresh()])->filter->isVerified())->toHaveCount(1);
});

test('removing a domain deletes it from SES and sends from the platform again', function (): void {
    $tenant = sendingDomainTenant();
    TenantSendingDomain::query()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'events.techsummit.gh',
        'from_address' => 'hello@events.techsummit.gh',
        'status' => TenantSendingDomain::STATUS_VERIFIED,
    ]);
    $this->ses->append(new Result([]));

    $this->actingAs(platformSuperadmin())
        ->delete(route('tenants.sending-domain.destroy', $tenant->uuid))
        ->assertSessionHas('success');

    expect($tenant->fresh()->sendingDomain)->toBeNull()
        ->and($this->ses->getLastCommand()->getName())->toBe('DeleteEmailIdentity');
});

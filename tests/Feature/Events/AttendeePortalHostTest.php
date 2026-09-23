<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enum\TenantStatusEnum;
use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Models\UsageLimit;
use App\Models\UsageRollup;
use App\Models\User;
use App\Services\Events\AttendeeVerification;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * Which organiser a request belongs to decides whose attendee history it can
 * see, so on the portal that answer may come only from the address bar.
 *
 * Tenant resolution has five sources. Two are the host -- a verified custom
 * domain, and the subdomain. The others are the session, an X-Tenant header
 * the caller sends, and a route parameter. The subdomain routes match any
 * label, including reserved ones like www, so www.<domain>/my reaches these
 * routes with no tenant in the host and falls through to whatever the caller
 * supplied.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
});

function baseDomain(): string
{
    return mb_ltrim((string) config('session.domain'), '.');
}

test('the portal opens when the organiser is named in the host', function () {
    $tenant = Tenant::factory()->create(['slug' => 'host-ok', 'isolation_mode' => 'shared']);
    $host = 'host-ok.'.baseDomain();

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('organiser.name', $tenant->name));
});

test('dns-equivalent host spelling reaches the tenant portal', function (string $spelling): void {
    $tenant = Tenant::factory()->create(['slug' => 'dns-host', 'isolation_mode' => 'shared']);
    $host = match ($spelling) {
        'uppercase' => 'DNS-HOST.'.mb_strtoupper(baseDomain()),
        'terminal-dot' => 'dns-host.'.baseDomain().'.',
    };

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('organiser.name', $tenant->name));
})->with([
    'uppercase' => 'uppercase',
    'one terminal dot' => 'terminal-dot',
]);

test('a header cannot name the organiser on a host that does not', function () {
    $tenant = Tenant::factory()->create(['slug' => 'header-victim', 'isolation_mode' => 'shared']);
    $host = 'www.'.baseDomain();

    // www is reserved, so nothing in the host names an organiser. Without the
    // guard the X-Tenant header decides, and the page answers for that tenant.
    $this->get("http://{$host}/my", ['HTTP_HOST' => $host, 'HTTP_X_TENANT' => $tenant->slug])
        ->assertNotFound();
});

test('a rejected host answers before tenant status validation', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'banned-victim',
        'status' => TenantStatusEnum::BANNED,
        'isolation_mode' => 'shared',
    ]);
    $host = 'www.'.baseDomain();

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host, 'HTTP_X_TENANT' => $tenant->slug])
        ->assertNotFound();
});

test('a rejected host answers before tenant membership validation', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'member-victim', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $host = 'www.'.baseDomain();

    $this->actingAs($user)
        ->get("http://{$host}/my", ['HTTP_HOST' => $host, 'HTTP_X_TENANT' => $tenant->slug])
        ->assertNotFound();
});

test('a rejected host answers before tenant usage enforcement', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'limit-victim', 'isolation_mode' => 'shared']);
    UsageRollup::create([
        'tenant_id' => $tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'period' => 'day',
        'period_start' => now()->startOfDay(),
        'value' => 800,
        'dimensions_hash' => 'portal-host-test',
    ]);
    UsageLimit::create([
        'tenant_id' => $tenant->id,
        'metric' => UsageMetric::REQUEST_COUNT,
        'limit_value' => 500,
        'period' => 'month',
        'block_on_limit' => true,
    ]);
    $host = 'www.'.baseDomain();

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host, 'HTTP_X_TENANT' => $tenant->slug])
        ->assertNotFound();
});

test('a session cannot name the organiser on a host that does not', function () {
    $tenant = Tenant::factory()->create(['slug' => 'session-victim', 'isolation_mode' => 'shared']);
    $host = 'www.'.baseDomain();

    $this->withSession(['active_tenant_id' => $tenant->id])
        ->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('a proof already held is not honoured through a header', function () {
    $tenant = Tenant::factory()->create(['slug' => 'proof-victim', 'isolation_mode' => 'shared']);
    $host = 'www.'.baseDomain();

    // The visitor genuinely proved this address on the organiser's own
    // subdomain. It still must not travel to a host that does not name them:
    // the guard answers before the verification does.
    $this->withSession(["attendee_verified.{$tenant->id}" => [
        'email' => 'ama@stem.org',
        'expires_at' => now()->addMinutes(AttendeeVerification::VERIFIED_MINUTES)->getTimestamp(),
    ]])
        ->getJson("http://{$host}/my/session", ['HTTP_HOST' => $host, 'HTTP_X_TENANT' => $tenant->slug])
        ->assertNotFound();
});

test('no code is sent for an organiser only a header names', function () {
    $tenant = Tenant::factory()->create(['slug' => 'code-victim', 'isolation_mode' => 'shared']);
    $host = 'www.'.baseDomain();

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], [
        'HTTP_HOST' => $host,
        'HTTP_X_TENANT' => $tenant->slug,
    ])->assertNotFound();

    Mail::assertNothingQueued();
});

test('an unresolved host cannot reuse a tenant from an earlier request', function (): void {
    Tenant::factory()->create(['slug' => 'first-host', 'isolation_mode' => 'shared']);
    $tenantHost = 'first-host.'.baseDomain();
    $reservedHost = 'www.'.baseDomain();

    $this->get("http://{$tenantHost}/my", ['HTTP_HOST' => $tenantHost])
        ->assertOk();

    $usageEventsAfterValidRequest = UsageEvent::query()->count();

    $this->get("http://{$reservedHost}/my", ['HTTP_HOST' => $reservedHost])
        ->assertNotFound();

    expect(app(TenantContext::class)->getTenant())->toBeNull()
        ->and(app(TenantContext::class)->activeTenantId())->toBeNull()
        ->and(UsageEvent::query()->count())->toBe($usageEventsAfterValidRequest);
});

test('an external custom domain does not claim attendee portal support', function (): void {
    Tenant::factory()->create([
        'slug' => 'branded',
        'isolation_mode' => 'shared',
        'custom_domain' => 'events.brand.test',
        'custom_domain_status' => 'active',
        'custom_domain_verified_at' => now(),
    ]);

    $this->get('https://events.brand.test/my', ['HTTP_HOST' => 'events.brand.test'])
        ->assertNotFound();
});

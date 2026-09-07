<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;

/**
 * A subdomain naming an organization that does not exist used to fall through to
 * the central catch-all routes and render the marketing landing page on a 200,
 * which tells the visitor the organization is real. No route in the subdomain
 * group matches "/", so such a request arrives with no {subdomain} route
 * parameter at all and the resolver's existing 404 branch never fired.
 */
function baseDomain(): string
{
    return mb_ltrim((string) config('session.domain'), '.');
}

test('an unknown tenant subdomain is not served the marketing page', function (): void {
    $host = 'nosuchtenant.'.baseDomain();

    $response = $this->get("http://{$host}/", ['HTTP_HOST' => $host]);

    $response->assertNotFound();
});

test('a real tenant subdomain still resolves', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $host = "{$tenant->slug}.".baseDomain();

    // Unauthenticated, so a redirect to login is the expected outcome. What
    // matters is that the host resolved to a tenant rather than 404ing.
    $response = $this->get("http://{$host}/dashboard", ['HTTP_HOST' => $host]);

    expect($response->status())->not->toBe(404);
});

test('the www subdomain is never treated as a tenant', function (): void {
    $host = 'www.'.baseDomain();

    $response = $this->get("http://{$host}/", ['HTTP_HOST' => $host]);

    expect($response->status())->not->toBe(404);
});

test('the apex domain is unaffected', function (): void {
    $host = baseDomain();

    $response = $this->get("http://{$host}/", ['HTTP_HOST' => $host]);

    expect($response->status())->not->toBe(404);
});

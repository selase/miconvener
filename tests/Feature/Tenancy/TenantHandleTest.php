<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Package;
use App\Models\Tenant;
use App\Support\TenantHandle;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Artisan;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;

/**
 * A handle becomes a subdomain printed on tickets and shared in messages, so it
 * has to be short, valid as a DNS label, and clear of the hostnames the platform
 * serves itself.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Location::shouldReceive('get')->andReturn(Position::make(['countryName' => 'Testland']));
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    test()->seed(EventPackageSeeder::class);
});

function registerWith(string $handle, string $email = 'ada@example.com'): \Illuminate\Testing\TestResponse
{
    return test()
        ->withoutMiddleware(\App\Http\Middleware\PreventRequestForgery::class)
        ->post('/register', [
            'first_name' => 'Ada',
            'last_name' => 'Osei',
            'organization_name' => 'Acme Events',
            'email' => $email,
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'plan' => Package::query()->where('slug', 'starter')->firstOrFail()->slug,
            'slug' => $handle,
        ]);
}

test('a reserved hostname cannot be claimed as a handle', function (string $reserved): void {
    // Handing one of these to a tenant would either break a hostname the platform
    // serves or let them impersonate it to their own attendees.
    registerWith($reserved)->assertSessionHasErrors('slug');

    expect(Tenant::query()->where('slug', $reserved)->exists())->toBeFalse();
})->with(['www', 'api', 'admin', 'billing', 'webhooks', 'tickets', 'support']);

test('a malformed handle is rejected', function (string $handle): void {
    registerWith($handle)->assertSessionHasErrors('slug');
})->with([
    'Acme Events',      // spaces
    'ACME',             // uppercase
    '-acme',            // leading hyphen
    'acme-',            // trailing hyphen
    'ac',               // shorter than the minimum
    'acme_events',      // underscore is not a valid DNS label character
    'acme.events',      // dots would create a deeper subdomain
]);

test('a handle longer than the maximum is rejected', function (): void {
    registerWith(str_repeat('a', TenantHandle::MAX_LENGTH + 1))->assertSessionHasErrors('slug');
});

test('a handle already taken is rejected', function (): void {
    Tenant::factory()->create(['slug' => 'acme-events', 'isolation_mode' => 'shared']);

    registerWith('acme-events')->assertSessionHasErrors('slug');
});

test('a valid handle is accepted and used verbatim', function (): void {
    registerWith('accra-tech-week')->assertSessionHasNoErrors();

    expect(Tenant::query()->where('slug', 'accra-tech-week')->exists())->toBeTrue();
});

test('a suggested handle is derived from the organization name', function (): void {
    expect(TenantHandle::suggestFrom('Accra Tech Week'))->toBe('accra-tech-week');
});

test('a suggested handle never exceeds the maximum or ends in a hyphen', function (): void {
    $suggested = TenantHandle::suggestFrom('Ghana Association of Professional Event Planners and Organizers');

    expect(mb_strlen($suggested))->toBeLessThanOrEqual(TenantHandle::MAX_LENGTH);
    expect($suggested)->not->toEndWith('-');
    expect($suggested)->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
});

test('a suggestion avoids one already taken', function (): void {
    Tenant::factory()->create(['slug' => 'accra-tech-week', 'isolation_mode' => 'shared']);

    expect(TenantHandle::suggestFrom('Accra Tech Week'))->toBe('accra-tech-week-2');
});

test('a suggestion avoids a reserved hostname', function (): void {
    // "Support" is a plausible organization name and a hostname we serve.
    expect(TenantHandle::suggestFrom('Support'))->not->toBe('support');
    expect(TenantHandle::isReserved(TenantHandle::suggestFrom('Support')))->toBeFalse();
});

test('a name with no usable characters still yields a valid handle', function (): void {
    $suggested = TenantHandle::suggestFrom('株式会社');

    expect(mb_strlen($suggested))->toBeGreaterThanOrEqual(TenantHandle::MIN_LENGTH);
    expect($suggested)->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
});

<?php

declare(strict_types=1);

use App\Models\Package;
use Database\Seeders\EventPackageSeeder;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;

beforeEach(function () {
    refreshTenantDatabases();

    Location::shouldReceive('get')->andReturn(
        Position::make(['countryName' => 'Testland'])
    );

    $this->seed(EventPackageSeeder::class);
});

test('registration screen can be rendered', function () {
    $appName = config('app.name');

    $this->get('/register')
        ->assertStatus(200)
        ->assertSee("Start your {$appName} workspace")
        // Every self-serve plan appears, Free included. Enterprise is excluded
        // because it has no numeric price and routes to a sales conversation.
        ->assertSee('Free')
        ->assertSee('Starter')
        ->assertSee('Growth')
        ->assertDontSee('Enterprise');
});

test('the registration screen preselects the plan the visitor arrived to choose', function () {
    // The pricing table links to /register?plan=free. If the query string were
    // ignored, that CTA would silently land on the paid default.
    $this->get('/register?plan=free')
        ->assertStatus(200)
        ->assertSee('value="free" class="sr-only peer"', escape: false);

    $html = $this->get('/register?plan=free')->getContent();
    $freeInput = mb_substr($html, mb_strpos($html, 'value="free"'), 200);

    expect($freeInput)->toContain('checked');
});

test('new users can register with a paid plan', function () {
    $plan = Package::where('slug', 'starter')->firstOrFail();

    $response = $this
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('/register', [
            'first_name' => 'Ada',
            'last_name' => 'Osei',
            'organization_name' => 'Acme Corp',
            'email' => 'ada@acmecorp.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'plan' => $plan->slug,
            'slug' => 'acme-corp',
        ]);

    $response->assertRedirect(route('billing.confirm', ['plan' => 'starter', 'interval' => 'month']));
    $this->assertAuthenticated();
});

test('new users can register on the free plan without paying', function () {
    $freePlan = Package::where('slug', 'free')->firstOrFail();

    $this
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('/register', [
            'first_name' => 'Ada',
            'last_name' => 'Osei',
            'organization_name' => 'Acme Corp',
            'email' => 'ada@acmecorp.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'plan' => $freePlan->slug,
            'slug' => 'acme-free',
        ])
        // Straight into the product. A free signup never reaches the payment
        // callback, so the plan is granted at signup instead.
        ->assertRedirect(route('tenant.onboarding.wizard', ['subdomain' => 'acme-free']));

    $this->assertAuthenticated();

    $tenant = App\Models\Tenant::where('slug', 'acme-free')->firstOrFail();
    expect((string) $tenant->package_id)->toBe((string) $freePlan->id);
});

test('a free signup gets the free plan limits it was promised', function () {
    $this
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('/register', [
            'first_name' => 'Ada',
            'last_name' => 'Osei',
            'organization_name' => 'Acme Corp',
            'email' => 'ada@acmecorp.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'plan' => 'free',
            'slug' => 'acme-free',
        ]);

    $tenant = App\Models\Tenant::where('slug', 'acme-free')->firstOrFail();

    // The advertised ceiling has to be the enforced one, not decoration.
    expect($tenant->featureLimitValue('event_registrations'))->toBe(50);
    expect($tenant->featureLimitValue('events_in_flight'))->toBe(1);
    expect($tenant->planAllows('paid_tickets'))->toBeFalse();
    expect($tenant->planAllows('event_materials'))->toBeFalse();
});

test('registration creates a tenant for the new user', function () {
    $plan = Package::where('slug', 'starter')->firstOrFail();

    $this
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('/register', [
            'first_name' => 'Ada',
            'last_name' => 'Osei',
            'organization_name' => 'Test Organization',
            'email' => 'ada@test.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'plan' => $plan->slug,
            'slug' => 'test-organization',
        ]);

    $user = App\Models\User::where('email', 'ada@test.com')->firstOrFail();

    expect($user->tenants()->count())->toBe(1);

    $tenant = $user->tenants()->first();
    expect($tenant->name)->toBe('Test Organization');
    // The handle the organizer chose, not one derived from the name behind
    // their back.
    expect($tenant->slug)->toBe('test-organization');

    // Signups share the landlord database. A dedicated database per signup would
    // provision one Postgres database per tenant for tables the product does not
    // use, against the hosting provider's per-cluster database limit.
    expect($tenant->isolation_mode)->toBe('shared');
});

test('registration requires all fields', function () {
    $this
        ->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)
        ->post('/register', [])
        ->assertSessionHasErrors(['first_name', 'last_name', 'organization_name', 'email', 'password', 'plan']);
});

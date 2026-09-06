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
        ->assertSee('Choose a paid plan, create your account, and provision your first tenant.')
        // Only paid, self-serve plans appear. Free is excluded because the
        // controller rejects it; Enterprise is excluded because it has no
        // numeric price and routes to a sales conversation instead.
        ->assertSee('Starter')
        ->assertSee('Growth')
        ->assertDontSee('Enterprise')
        ->assertDontSee('Free');
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
        ]);

    $response->assertRedirect(route('billing.confirm', ['plan' => 'starter', 'interval' => 'month']));
    $this->assertAuthenticated();
});

test('registration requires a paid plan', function () {
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
        ])
        ->assertSessionHasErrors('plan');
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
        ]);

    $user = App\Models\User::where('email', 'ada@test.com')->firstOrFail();

    expect($user->tenants()->count())->toBe(1);

    $tenant = $user->tenants()->first();
    expect($tenant->name)->toBe('Test Organization');
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

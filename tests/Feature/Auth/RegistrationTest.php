<?php

declare(strict_types=1);

use App\Models\Package;
use App\Services\Tenancy\TenantProvisioner;
use Database\Seeders\MasterFeaturePackageSeeder;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;

beforeEach(function () {
    refreshTenantDatabases();

    Location::shouldReceive('get')->andReturn(
        Position::make(['countryName' => 'Testland'])
    );

    $this->seed(MasterFeaturePackageSeeder::class);

    // Prevent actual DB provisioning during registration tests
    $this->mock(TenantProvisioner::class)
        ->shouldReceive('provision')
        ->andReturnNull();
});

test('registration screen can be rendered', function () {
    $this->get('/register')
        ->assertStatus(200)
        ->assertSee('Start your QNotify workspace')
        ->assertSee('Choose a paid plan, create your account, and launch your first branch queue.')
        ->assertSee('Pro')
        ->assertSee('Business')
        ->assertSee('Enterprise')
        ->assertDontSee('Free');
});

test('new users can register with a paid plan', function () {
    $plan = Package::where('slug', 'pro')->firstOrFail();

    $response = $this
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->post('/register', [
            'first_name' => 'Ada',
            'last_name' => 'Osei',
            'organization_name' => 'Acme Corp',
            'email' => 'ada@acmecorp.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'plan' => $plan->slug,
        ]);

    $response->assertRedirect(route('billing.confirm', ['plan' => 'pro', 'interval' => 'month']));
    $this->assertAuthenticated();
});

test('registration requires a paid plan', function () {
    $freePlan = Package::where('slug', 'free')->firstOrFail();

    $this
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
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
    $plan = Package::where('slug', 'pro')->firstOrFail();

    $this
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
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
});

test('registration requires all fields', function () {
    $this
        ->withoutMiddleware(App\Http\Middleware\VerifyCsrfToken::class)
        ->post('/register', [])
        ->assertSessionHasErrors(['first_name', 'last_name', 'organization_name', 'email', 'password', 'plan']);
});

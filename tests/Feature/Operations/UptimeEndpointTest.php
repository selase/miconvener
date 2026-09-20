<?php

declare(strict_types=1);

use App\Models\User;

/**
 * An uptime monitor is the one caller that shows up when nobody is logged in
 * and the application may already be broken. /health has to answer it directly:
 * a redirect to the login form reads as "reachable" to most monitors, which is
 * exactly the wrong answer during an outage.
 *
 * routes/public.php loads after routes/web.php, so a route registered there on
 * `health` silently replaces this one. That is what these tests pin down.
 */
test('the uptime endpoint answers without a session', function () {
    $this->get('/health')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'database' => 'ok'])
        ->assertJsonStructure(['status', 'database', 'timestamp']);
});

test('the uptime endpoint is not behind auth middleware', function () {
    $route = app('router')->getRoutes()->getByName('health');

    expect($route)->not->toBeNull();
    expect($route->uri())->toBe('health');
    // Any auth middleware here turns a 200/503 health signal into a 302.
    expect($route->gatherMiddleware())->toBe(['web']);
});

test('the health dashboard keeps its own address, and still requires signing in', function () {
    $route = app('router')->getRoutes()->getByName('application.health');

    expect($route)->not->toBeNull();
    // It must not sit on `health`, or it takes the uptime endpoint's address.
    expect($route->uri())->toBe('health/dashboard');
    expect($route->gatherMiddleware())->toContain('auth');

    $this->get('/health/dashboard')->assertRedirect(route('login'));
});

test('a signed-in superadmin still reaches the health dashboard through its name', function () {
    $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

    // The sidebar links by name, so moving the URI must not break the link.
    expect(route('application.health'))->toEndWith('/health/dashboard');

    $this->actingAs($user)->get(route('application.health'))->assertSuccessful();
});

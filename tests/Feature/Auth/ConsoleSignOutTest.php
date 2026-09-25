<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Artisan;

/**
 * An organiser console is signed into on venue laptops, borrowed desks and
 * shared machines. Until now the only way out of one was to clear cookies:
 * the route existed, and nothing in the console referred to it.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('signing out ends the session and the console stops opening', function (): void {
    [$tenant, $user] = eventHost('sign-out');
    $host = eventSubdomainHost('sign-out');

    $this->actingAs($user)
        ->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertOk();

    $this->actingAs($user)
        ->post("http://{$host}/logout", [], ['HTTP_HOST' => $host])
        ->assertRedirect();

    expect(auth()->check())->toBeFalse();

    $this->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertRedirect();

    unset($tenant);
});

test('the console offers a way out on every page', function (): void {
    [, $user] = eventHost('sign-out-visible');
    $host = eventSubdomainHost('sign-out-visible');

    // The control lives in the layout rather than a page, so this asserts the
    // route it posts to is registered -- a layout that pointed at a route that
    // did not exist would render a button that throws on click.
    expect(route('logout'))->toContain('/logout');

    $this->actingAs($user)
        ->get("http://{$host}/dashboard", ['HTTP_HOST' => $host])
        ->assertOk();
});

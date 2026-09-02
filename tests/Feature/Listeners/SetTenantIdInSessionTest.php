<?php

declare(strict_types=1);

use App\Listeners\SetTenantIdInSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Session;

it('sets tenant id in session on login when user has tenants', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create([
        'name' => 'Session Tenant',
        'slug' => 'session-tenant',
        'isolation_mode' => 'shared',
    ]);
    $tenant->users()->attach($user->id);

    Session::forget('active_tenant_id');

    $event = new Login('web', $user, false);

    $listener = new SetTenantIdInSession();
    $listener->handle($event);

    expect(Session::get('active_tenant_id'))->toBe($tenant->id);
});

it('does not set tenant id when user has no tenants', function () {
    $user = User::factory()->create();

    Session::forget('active_tenant_id');

    $event = new Login('web', $user, false);

    $listener = new SetTenantIdInSession();
    $listener->handle($event);

    expect(Session::has('active_tenant_id'))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserLoginHistory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

it('allows viewing login history for same tenant', function () {
    $tenant = setActiveTenantForTest();

    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    $history = UserLoginHistory::forceCreate([
        'uuid' => Str::uuid(),
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'session_id' => 'sess-same-tenant',
    ]);

    $this->actingAs($user);

    expect(Gate::allows('view', $history))->toBeTrue();
});

it('denies viewing login history for different tenant', function () {
    $tenantA = setActiveTenantForTest();

    $tenantB = Tenant::factory()->create([
        'name' => 'Other Tenant',
        'slug' => 'other-tenant',
        'isolation_mode' => 'shared',
    ]);

    $user = User::factory()->create();
    $tenantA->users()->attach($user->id);

    $history = UserLoginHistory::forceCreate([
        'uuid' => Str::uuid(),
        'tenant_id' => $tenantB->id,
        'user_id' => 999,
        'session_id' => 'sess-other-tenant',
    ]);

    $this->actingAs($user);

    expect(Gate::allows('view', $history))->toBeFalse();
});

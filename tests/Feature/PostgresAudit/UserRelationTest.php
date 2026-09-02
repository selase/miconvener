<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user relations work with string key type', function () {
    // create user (Int ID)
    $user = User::factory()->create();

    // create tenant
    $tenant = Tenant::factory()->create();

    // attach (writes to tenant_user pivot)
    $user->tenants()->attach($tenant);

    // querying
    $check = $user->tenants()->first();

    expect($check)->not->toBeNull();
    expect($check->id)->toBe($tenant->id);
});

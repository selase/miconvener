<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('log context includes tenant id when resolved', function () {
    $tenant = Tenant::create(['name' => 'Log Tenant', 'slug' => 'log-tenant', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    Log::shouldReceive('withContext')
        ->once()
        ->with(['tenant_id' => $tenant->id]);

    // Allow other log calls (like 'info' from middleware or 'error' from exception handler)
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();

    $this->actingAs($user)
        ->getJson('/dashboard', ['X-Tenant' => $tenant->id]);
});

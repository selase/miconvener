<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Spatie\Health\Enums\Status;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);

    Config::set('health.json_results_failure_status', 500);
    Config::set('health.checks', []);
});

function fakeHealthResults(array $results): void
{
    app()->instance(ResultStore::class, new class($results) implements ResultStore
    {
        public function __construct(private array $results) {}

        public function save(Illuminate\Support\Collection $checkResults): void {}

        public function latestResults(): ?StoredCheckResults
        {
            return new StoredCheckResults(checkResults: collect($this->results));
        }
    });
}

test('health check endpoint is accessible and returns 200', function () {
    $tenant = Tenant::factory()->create(['name' => 'Health Tenant', 'slug' => 'health-tenant', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    // Fake Health to always pass
    fakeHealthResults([
        new StoredCheckResult(
            name: 'database',
            label: 'Database',
            status: Status::ok()->value,
        ),
    ]);

    $response = $this->actingAs($user)->getJson('/health/json', ['X-Tenant' => $tenant->id]);
    $response->assertStatus(200);
});

test('health check endpoint is accessible and returns 500 on failure', function () {
    $tenant = Tenant::factory()->create(['name' => 'Health Tenant', 'slug' => 'health-tenant', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    // Fake Health to always fail
    fakeHealthResults([
        new StoredCheckResult(
            name: 'database',
            label: 'Database',
            status: Status::failed()->value,
        ),
    ]);

    $response = $this->actingAs($user)->getJson('/health/json', ['X-Tenant' => $tenant->id]);
    $response->assertStatus(500);
});

test('health check contains database check', function () {
    $tenant = Tenant::create(['name' => 'Health Tenant', 'slug' => 'health-tenant', 'isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id);

    $this->actingAs($user)->getJson('/health/json', ['X-Tenant' => $tenant->id])
        ->assertJsonFragment(['label' => 'Database']);
});

<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\FeatureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);

    $this->tenant = Tenant::create([
        'name' => 'Test Tenant',
        'slug' => 'test-tenant',
        'isolation_mode' => 'shared',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    // Register a temporary route for testing middleware
    Route::get('/test-feature', function () {
        return response('OK');
    })->middleware(['web', 'feature:test-feature']);
});

test('middleware denies access if feature disabled', function () {
    $user = User::factory()->create();
    $this->tenant->users()->attach($user->id);

    $this->actingAs($user)->get('/test-feature')->assertStatus(403);
});

test('middleware allows access if feature enabled', function () {
    app(FeatureService::class)->enable('test-feature');

    $user = User::factory()->create();
    $this->tenant->users()->attach($user->id);

    $this->actingAs($user)->get('/test-feature')->assertStatus(200);
});

test('blade directive renders content if feature enabled', function () {
    app(FeatureService::class)->enable('test-feature');

    $string = "@feature('test-feature') Feature Content @endfeature";
    $rendered = Blade::compileString($string);

    expect($rendered)->toContain("if (\Illuminate\Support\Facades\Blade::check('feature', 'test-feature'))");
});

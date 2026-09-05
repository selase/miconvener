<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\TenantFeatureUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a confirmed free registration records event_registrations usage for the tenant', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme4', 'isolation_mode' => 'shared']);
    $host = 'acme4.'.mb_ltrim((string) config('session.domain'), '.');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'event_registrations',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 100],
    ]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host]);

    $usage = TenantFeatureUsage::where('tenant_id', $tenant->id)
        ->where('feature_slug', 'event_registrations')
        ->whereNull('period_start')
        ->value('used_count');

    expect($usage)->toBe(1);
});

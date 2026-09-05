<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\TenantFeatureUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('sending a registration confirmation email records email_credits usage', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme5', 'isolation_mode' => 'shared']);
    $host = 'acme5.'.mb_ltrim((string) config('session.domain'), '.');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host]);

    $usage = TenantFeatureUsage::where('tenant_id', $tenant->id)
        ->where('feature_slug', 'email_credits')
        ->whereNull('period_start')
        ->value('used_count');

    expect($usage)->toBe(1);
});

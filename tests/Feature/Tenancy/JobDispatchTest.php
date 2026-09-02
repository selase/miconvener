<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('job processing event restores tenant context', function () {
    $tenant = Tenant::create(['name' => 'Event Tenant', 'slug' => 'event-tenant', 'isolation_mode' => 'shared']);

    // Ensure context is clear
    app(TenantContext::class)->setActiveTenantId(null);
    expect(app(TenantContext::class)->activeTenantId())->toBeNull();

    // Mock a job payload with tenant_id
    $payload = json_encode([
        'tenant_id' => $tenant->id,
        'job' => 'SomeJob',
        'data' => [],
    ]);

    // Mock the Job object
    $job = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn(json_decode($payload, true));

    // Fire the event
    $event = new JobProcessing('sync', $job);
    Event::dispatch($event);

    // Verify context restored
    expect(app(TenantContext::class)->activeTenantId())->toBe($tenant->id);
});

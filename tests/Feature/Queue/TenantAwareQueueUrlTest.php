<?php

declare(strict_types=1);

use App\Jobs\Events\SendEventBlastJob;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventRegistration;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * A queued job must be able to build a subdomain URL.
 *
 * ResolveTenant sets the subdomain URL default, but it only runs for an HTTP
 * request. Work that happens on a worker restores the tenant, its permissions
 * team, its database connection and its storage disk through the queue's
 * JobProcessing hook -- and, until this test, not the URL default. Any mail
 * rendered from a worker that links back to a tenant page therefore threw
 * "Missing required parameter ... [Missing parameter: subdomain]" instead of
 * sending, which is how the blast tracking pixel behaved.
 */
test('mail sent from a queued job can build a subdomain route', function (): void {
    [$tenant] = eventHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'queue-url-congress',
    ]);

    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'ama@stem.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $blast = EventBlast::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'audience' => 'all',
    ]);

    app(TenantContext::class)->setTenant($tenant);

    // No request has run, so nothing has set the default. This is the state a
    // worker starts in, and the mailer here is the array transport, so the
    // template is built for real rather than recorded unrendered.
    URL::defaults([]);

    SendEventBlastJob::dispatch($blast);

    // Reaching sent means every recipient's mail rendered; a missing route
    // parameter throws out of the job long before this.
    expect($blast->fresh()->status)->toBe(EventBlast::STATUS_SENT);
});

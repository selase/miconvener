<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * MaterialDownloadController gates a speaker material on a confirmed
 * registration, its release date, and a per-registration download limit. The
 * /media/{path} route serves the same files with none of that.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('an unreleased material is refused through the gated route but served by /media', function () {
    Storage::fake('public');

    $tenant = Tenant::factory()->create(['slug' => 'media-probe', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    Storage::disk('public')->put('event-materials/material_secret.pdf', 'CONFIDENTIAL SLIDE DECK');

    $material = EventMaterial::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Embargoed Keynote',
        'file_path' => 'event-materials/material_secret.pdf',
        'file_size' => 23,
        'mime_type' => 'application/pdf',
        'download_limit' => 1,
        'release_at' => now()->addDays(30),
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $host = 'media-probe.'.mb_ltrim((string) config('session.domain'), '.');

    // The designed route correctly refuses: not released yet.
    $this->get(
        "http://{$host}/e/{$event->slug}/registrations/{$registration->id}/materials/{$material->id}/download",
        ['HTTP_HOST' => $host]
    )->assertForbidden();

    // The same bytes, with no registration, no release check and no limit.
    $direct = $this->get("http://{$host}/media/event-materials/material_secret.pdf", ['HTTP_HOST' => $host]);

    expect($direct->getStatusCode())->not->toBe(200);
});

test('one tenant cannot stream another tenants material through /media', function () {
    Storage::fake('public');

    $victim = Tenant::factory()->create(['slug' => 'media-victim', 'isolation_mode' => 'shared']);
    $other = Tenant::factory()->create(['slug' => 'media-other', 'isolation_mode' => 'shared']);

    Storage::disk('public')->put('event-materials/material_victimdoc.pdf', 'VICTIM ONLY');

    $host = 'media-other.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->get("http://{$host}/media/event-materials/material_victimdoc.pdf", ['HTTP_HOST' => $host]);

    expect($response->getStatusCode())->not->toBe(200);
});

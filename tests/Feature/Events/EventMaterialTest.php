<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Storage::fake('public');
});

test('host can upload a material', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/events/{$event->id}/materials", [
        'title' => 'Opening slides',
        'download_limit' => 2,
        'file' => UploadedFile::fake()->create('slides.pdf', 500, 'application/pdf'),
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect(EventMaterial::where('event_id', $event->id)->where('title', 'Opening slides')->exists())->toBeTrue();
});

test('a confirmed registrant can download a released material up to their attempt limit', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $material = EventMaterial::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'download_limit' => 2]);

    Storage::disk('public')->put($material->file_path, 'fake-pdf-content');

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";
    $url = "http://{$host}/e/{$event->slug}/registrations/{$registration->id}/materials/{$material->id}/download";

    // The attendee receives the file itself. A redirect used to be asserted
    // here, which proved only that the code handed off: in production it
    // handed off to an unsigned object-storage URL that answered 400.
    expect($this->get($url, ['HTTP_HOST' => $host])->assertOk()->streamedContent())->toBe('fake-pdf-content');
    expect($this->get($url, ['HTTP_HOST' => $host])->assertOk()->streamedContent())->toBe('fake-pdf-content');
    $this->get($url, ['HTTP_HOST' => $host])->assertStatus(429);

    expect($material->fresh()->downloads()->count())->toBe(2);
});

test('a downloaded pdf opens in the browser rather than being saved', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $material = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Opening Keynote',
        'file_path' => 'event-materials/keynote.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('public')->put($material->file_path, '%PDF-1.7 keynote');

    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    // Most attendees open slides on a phone between sessions. Inline lets the
    // browser show the PDF straight away, named after the material rather
    // than the random name it was stored under.
    $response = $this->get(
        "http://{$host}/e/{$event->slug}/registrations/{$registration->id}/materials/{$material->id}/download",
        ['HTTP_HOST' => $host]
    )->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($response->headers->get('Content-Disposition'))->toContain('Opening Keynote.pdf');
});

test('a material whose file is missing does not use up an attempt', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $material = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'file_path' => 'event-materials/never-uploaded.pdf',
        'download_limit' => 1,
    ]);

    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');
    $url = "http://{$host}/e/{$event->slug}/registrations/{$registration->id}/materials/{$material->id}/download";

    // An attendee has only a few attempts. One spent on a file that could not
    // be served is one they can never get back.
    $this->get($url, ['HTTP_HOST' => $host])->assertNotFound();

    expect($material->fresh()->downloads()->count())->toBe(0);
});

test('a material scheduled for later cannot be downloaded yet', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $material = EventMaterial::factory()->releasedLater()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}/materials/{$material->id}/download", ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('a material titled the way speaker slides are titled still downloads', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    // The speaker portal names every deck "{speaker} — Slides", with an em
    // dash. A Content-Disposition filename that is not ASCII needs a fallback,
    // and one containing a slash is refused outright, so both are exercised.
    $material = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Dr. Ama Serwaa / Kofi Mensah — Slides',
        'file_path' => 'event-materials/deck.pptx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ]);

    Storage::disk('public')->put($material->file_path, 'pptx-bytes');

    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->get(
        "http://{$host}/e/{$event->slug}/registrations/{$registration->id}/materials/{$material->id}/download",
        ['HTTP_HOST' => $host]
    )->assertOk();

    expect($response->streamedContent())->toBe('pptx-bytes')
        ->and($response->headers->get('Content-Disposition'))->toContain('.pptx');
});

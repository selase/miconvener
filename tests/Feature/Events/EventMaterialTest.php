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

    $this->get($url, ['HTTP_HOST' => $host])->assertRedirect();
    $this->get($url, ['HTTP_HOST' => $host])->assertRedirect();
    $this->get($url, ['HTTP_HOST' => $host])->assertStatus(429);

    expect($material->fresh()->downloads()->count())->toBe(2);
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

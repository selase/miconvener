<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * event_materials cascades at the database level, so deleting an event drops the
 * rows that point at its files -- destroying the only record of what to clean up.
 * Files that outlive their rows are unreachable and billed forever.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('deleting an event removes its hero image and material files', function () {
    Storage::fake('public');

    $tenant = Tenant::factory()->create(['slug' => 'cleanup-one', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    Storage::disk('public')->put('events/hero/hero.jpg', 'hero-bytes');
    Storage::disk('public')->put('event-materials/deck.pdf', 'deck-bytes');
    $event->update(['hero_image_path' => 'events/hero/hero.jpg']);

    EventMaterial::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Deck',
        'file_path' => 'event-materials/deck.pdf',
        'file_size' => 10,
        'mime_type' => 'application/pdf',
        'download_limit' => 5,
    ]);

    Storage::disk('public')->assertExists('events/hero/hero.jpg');
    Storage::disk('public')->assertExists('event-materials/deck.pdf');

    $event->delete();

    Storage::disk('public')->assertMissing('events/hero/hero.jpg');
    Storage::disk('public')->assertMissing('event-materials/deck.pdf');
});

test('replacing a hero image deletes the one it replaced', function () {
    Storage::fake('public');

    $tenant = Tenant::factory()->create(['slug' => 'cleanup-two', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    Storage::disk('public')->put('events/hero/old.jpg', 'old-bytes');
    $event->update(['hero_image_path' => 'events/hero/old.jpg']);

    $host = 'cleanup-two.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($user)->put("http://{$host}/events/{$event->id}", [
        'name' => $event->name,
        'description' => 'Updated',
        'status' => Event::STATUS_DRAFT,
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => '123 Main St',
        'ticket_price' => 0,
        'currency' => 'GHS',
        'hero_image' => UploadedFile::fake()->image('new.jpg'),
    ], ['HTTP_HOST' => $host]);

    Storage::disk('public')->assertMissing('events/hero/old.jpg');
    expect($event->fresh()->hero_image_path)->not->toBe('events/hero/old.jpg');
});

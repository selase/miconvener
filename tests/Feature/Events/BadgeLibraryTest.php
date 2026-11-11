<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Badges\BadgeTemplateService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($this->user->id);
    setPermissionsTeamId($this->tenant->id);
    $this->user->assignRole('Org Superadmin');
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->design = app(BadgeTemplateService::class)->forEvent($this->event);
    $this->host = eventSubdomainHost('acme');
    $this->base = "http://{$this->host}/events/{$this->event->id}/badges/library";
    $this->actingAs($this->user)->withHeaders(['HTTP_HOST' => $this->host]);
});

test('saves a named private design and applies a tenant shared copy without foreign ticket ids', function (): void {
    $saved = $this->postJson($this->base, ['name' => 'Conference', 'scope' => 'organisation', 'expected_version' => 1])->assertCreated();
    $version = $saved->json('version.id');
    $target = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    app(BadgeTemplateService::class)->forEvent($target);
    $this->getJson("http://{$this->host}/events/{$target->id}/badges/library")->assertOk()->assertJsonFragment(['name' => 'Conference']);
    $this->postJson("http://{$this->host}/events/{$target->id}/badges/library/apply", ['version_id' => $version, 'expected_version' => 1, 'category_labels' => []])
        ->assertOk()->assertJsonPath('template.design_version', 2)->assertJsonPath('template.sheet_settings.category_labels', []);
    $this->getJson("http://{$this->host}/events/{$target->id}/badges/library")->assertJsonCount(1, 'revisions');
});

test('private designs remain hidden from other events and stale replacements are refused', function (): void {
    $saved = $this->postJson($this->base, ['name' => 'Private design', 'scope' => 'event', 'expected_version' => 1])->assertCreated();
    $target = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->getJson("http://{$this->host}/events/{$target->id}/badges/library")->assertOk()->assertJsonMissing(['name' => 'Private design']);
    $this->postJson("http://{$this->host}/events/{$target->id}/badges/library/apply", ['version_id' => $saved->json('version.id'), 'expected_version' => 1])->assertNotFound();
    $this->design->update(['design_version' => 2]);
    $this->postJson($this->base.'/apply', ['version_id' => $saved->json('version.id'), 'expected_version' => 1])->assertUnprocessable();
});

test('restores the previous event design after application', function (): void {
    $saved = $this->postJson($this->base, ['name' => 'Original', 'scope' => 'event', 'expected_version' => 1])->assertCreated();
    $this->design->update(['width_mm' => 54, 'height_mm' => 85, 'design_version' => 2]);
    $this->postJson($this->base.'/apply', ['version_id' => $saved->json('version.id'), 'expected_version' => 2])->assertOk();
    $revision = $this->getJson($this->base)->json('revisions.0.id');
    $this->postJson($this->base.'/restore', ['revision_id' => $revision, 'expected_version' => 3])->assertOk()->assertJsonPath('template.width_mm', 54)->assertJsonPath('template.design_version', 4);
});

test('owned artwork survives source edits and library versions cannot be updated', function (): void {
    \Illuminate\Support\Facades\Storage::fake('public');
    \Illuminate\Support\Facades\Storage::fake('local');
    $file = \Illuminate\Http\UploadedFile::fake()->image('background.png', 540, 850);
    $asset = app(\App\Services\Design\ArtifactArtworkService::class)->store($file, $this->tenant->id, $this->event->id, 'badge-background');
    $this->design->update(['background_disk' => $asset->disk, 'background_path' => $asset->path]);
    $saved = $this->postJson($this->base, ['name' => 'Artwork', 'scope' => 'organisation', 'expected_version' => 1])->assertCreated();
    $version = \App\Models\BadgeLibraryVersion::findOrFail($saved->json('version.id'));
    \Illuminate\Support\Facades\Storage::disk('public')->delete($asset->path);
    $this->getJson($this->base.'/versions/'.$version->id)->assertOk()->assertJsonPath('background', fn ($value): bool => str_starts_with($value, 'data:image/png;'));
    expect(fn () => $version->update(['snapshot' => []]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('withdrawing a shared design preserves an already applied event copy', function (): void {
    $saved = $this->postJson($this->base, ['name' => 'Shared', 'scope' => 'organisation', 'expected_version' => 1])->assertCreated();
    $version = \App\Models\BadgeLibraryVersion::findOrFail($saved->json('version.id'));
    $this->postJson($this->base.'/apply', ['version_id' => $version->id, 'expected_version' => 1])->assertOk();
    $this->postJson($this->base.'/'.$version->design_id.'/withdraw')->assertOk();
    $this->getJson($this->base)->assertJsonMissing(['name' => 'Shared']);
    expect($this->design->fresh()->source_version_id)->toBe($version->id);
});

test('event organisers can use shared designs but cannot promote private designs', function (): void {
    $this->user->syncRoles(['Event Organiser']);
    \App\Models\EventStaffAssignment::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'user_id' => $this->user->id]);
    $this->postJson($this->base, ['name' => 'Private', 'scope' => 'event', 'expected_version' => 1])->assertCreated();
    $this->postJson($this->base, ['name' => 'Shared', 'scope' => 'organisation', 'expected_version' => 1])->assertForbidden();
    $other = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->getJson("http://{$this->host}/events/{$other->id}/badges/library")->assertNotFound();
});

test('paid designs cannot expose clean assets or apply through forged requests', function (): void {
    $saved = $this->postJson($this->base, ['name' => 'Premium', 'scope' => 'organisation', 'expected_version' => 1])->assertCreated();
    $version = \App\Models\BadgeLibraryVersion::findOrFail($saved->json('version.id'));
    $version->design->update(['scope' => 'platform', 'tenant_id' => null, 'price_pesewas' => 5000]);
    $this->postJson($this->base.'/apply', ['version_id' => $version->id, 'expected_version' => 1])->assertUnprocessable();
    $this->getJson($this->base.'/versions/'.$version->id)->assertOk()->assertJsonStructure(['preview_image'])->assertJsonMissingPath('template')->assertJsonMissingPath('background');
});

test('category mappings use destination ids and restore keeps the previous labels', function (): void {
    $ticket = \App\Models\EventTicketType::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    $settings = $this->design->sheetSettings();
    $settings['category_labels'] = [$ticket->id => 'Doctor'];
    $this->design->update(['sheet_settings' => $settings]);
    $saved = $this->postJson($this->base, ['name' => 'Labels', 'scope' => 'event', 'expected_version' => 1])->assertCreated();
    $this->postJson($this->base.'/apply', ['version_id' => $saved->json('version.id'), 'expected_version' => 1, 'category_labels' => [$ticket->id => 'Faculty']])->assertOk();
    $revision = $this->getJson($this->base)->json('revisions.0.id');
    $this->postJson($this->base.'/restore', ['revision_id' => $revision, 'expected_version' => 2])->assertOk()->assertJsonPath('template.sheet_settings.category_labels.'.$ticket->id, 'Doctor');
});

test('platform starter compositions are seeded idempotently in portrait and landscape', function (): void {
    Artisan::call('db:seed', ['--class' => 'BadgeLibrarySeeder']);
    Artisan::call('db:seed', ['--class' => 'BadgeLibrarySeeder']);
    $this->getJson($this->base)->assertOk()->assertJsonFragment(['name' => 'Spectrum lines']);
    $design = \App\Models\BadgeLibraryDesign::where('name', 'Spectrum lines')->firstOrFail();
    expect($design->versions()->count())->toBe(1);
    expect($design->versions()->first()->snapshot['compositions'])->toHaveKeys(['portrait', 'landscape']);
});

test('thumbnails are private and a different tenant cannot read or apply a shared design', function (): void {
    $saved = $this->postJson($this->base, ['name' => 'Shared artwork', 'scope' => 'organisation', 'expected_version' => 1])->assertCreated();
    $version = $saved->json('version.id');
    $this->get($this->base.'/thumbnails/'.$version)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
    $other = Tenant::factory()->create(['slug' => 'elsewhere', 'isolation_mode' => 'shared']);
    $otherEvent = Event::factory()->create(['tenant_id' => $other->id]);
    expect(app(\App\Services\Badges\BadgeLibraryService::class)->visible($otherEvent)->whereKey(\App\Models\BadgeLibraryVersion::find($version)->design_id)->exists())->toBeFalse();
    expect(fn () => app(\App\Services\Badges\BadgeLibraryService::class)->visibleVersion($otherEvent, $version))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('saving another version preserves the original and custom sizes must fit the paper', function (): void {
    $first = $this->postJson($this->base, ['name' => 'Reusable', 'scope' => 'organisation', 'expected_version' => 1])->assertCreated();
    $version = \App\Models\BadgeLibraryVersion::findOrFail($first->json('version.id'));
    $this->design->update(['width_mm' => 54, 'height_mm' => 85, 'design_version' => 2]);
    $this->postJson($this->base, ['name' => 'Reusable', 'scope' => 'organisation', 'expected_version' => 2, 'design_id' => $version->design_id])->assertCreated()->assertJsonPath('version.version', 2);
    expect($version->fresh()->snapshotSettings()['width_mm'])->toEqual(100);
    $this->postJson($this->base.'/apply', ['version_id' => $version->id, 'expected_version' => 2, 'width_mm' => 210])->assertUnprocessable();
    expect($this->design->fresh()->design_version)->toBe(2);
});

test('curated designs fully replace old backgrounds and duplex faces', function (string $name): void {
    \Illuminate\Support\Facades\Storage::fake('public');
    \Illuminate\Support\Facades\Storage::fake('local');
    $asset = app(\App\Services\Design\ArtifactArtworkService::class)->store(\Illuminate\Http\UploadedFile::fake()->image('old.png', 540, 850), $this->tenant->id, $this->event->id, 'badge-background');
    $this->design->update(['background_disk' => $asset->disk, 'background_path' => $asset->path, 'back' => ['layout' => $this->design->layoutSettings()]]);
    $version = \App\Models\BadgeLibraryDesign::where('name', $name)->firstOrFail()->versions()->firstOrFail();
    $response = $this->postJson($this->base.'/apply', ['version_id' => $version->id, 'expected_version' => 1, 'composition' => 'landscape'])->assertOk()->assertJsonPath('template.back', null)->assertJsonPath('template.width_mm', 100);
    if ($name === 'Essential') {
        $response->assertJsonPath('template.background_path', null)->assertJsonPath('template.background_disk', null);
    } else {
        expect($this->design->fresh()->background_path)->not->toBe($asset->path);
    }
})->with(['Essential', 'Spectrum lines']);

test('platform publication refuses tenant-owned fonts on either face', function (string $face): void {
    $layout = $this->design->layoutSettings();
    $layout['attendee_name']['font_family'] = 'artifact-tenant-owned';
    $this->design->update($face === 'front' ? ['layout' => $layout] : ['back' => ['layout' => $layout]]);
    expect(fn () => app(\App\Services\Badges\BadgeLibraryService::class)->save($this->event, ['scope' => 'platform', 'name' => 'Invalid font', 'expected_version' => 1]))->toThrow(\Illuminate\Validation\ValidationException::class);
})->with(['front', 'back']);

test('applying a design validates ownership of back-face fonts before replacement', function (): void {
    $snapshot = app(\App\Services\Badges\BadgeLibraryService::class)->snapshot($this->event, $this->design);
    $layout = $snapshot['layout'];
    $layout['attendee_name']['font_family'] = 'artifact-someone-elses';
    $snapshot['back'] = ['layout' => $layout];
    $design = \App\Models\BadgeLibraryDesign::create(['tenant_id' => $this->tenant->id, 'scope' => 'organisation', 'name' => 'Bad back']);
    $version = $design->versions()->create(['version' => 1, 'snapshot' => $snapshot]);
    $this->postJson($this->base.'/apply', ['version_id' => $version->id, 'expected_version' => 1])->assertUnprocessable();
    expect($this->design->fresh()->design_version)->toBe(1);
});

test('permanent source event deletion removes its back and revision artwork but preserves organisation copies', function (): void {
    \Illuminate\Support\Facades\Storage::fake('public');
    \Illuminate\Support\Facades\Storage::fake('local');
    $artwork = app(\App\Services\Design\ArtifactArtworkService::class);
    $front = $artwork->store(\Illuminate\Http\UploadedFile::fake()->image('front.png', 540, 850), $this->tenant->id, $this->event->id, 'badge-background');
    $back = $artwork->store(\Illuminate\Http\UploadedFile::fake()->image('back.png', 540, 850), $this->tenant->id, $this->event->id, 'badge-background');
    $this->design->update(['background_disk' => $front->disk, 'background_path' => $front->path, 'back' => ['layout' => $this->design->layoutSettings(), 'background_disk' => $back->disk, 'background_path' => $back->path]]);
    $saved = $this->postJson($this->base, ['name' => 'Keep artwork', 'scope' => 'organisation', 'expected_version' => 1])->assertCreated();
    $version = \App\Models\BadgeLibraryVersion::findOrFail($saved->json('version.id'));
    $this->postJson($this->base.'/apply', ['version_id' => $version->id, 'expected_version' => 1])->assertOk();
    $current = $this->design->fresh();
    $revision = \App\Models\BadgeDesignRevision::where('event_id', $this->event->id)->firstOrFail()->snapshotSettings();
    $private = $this->postJson($this->base, ['name' => 'Event private', 'scope' => 'event', 'expected_version' => 2])->assertCreated();
    $privateVersion = \App\Models\BadgeLibraryVersion::findOrFail($private->json('version.id'));
    $privateArtwork = $privateVersion->snapshotSettings();
    $this->event->forceDelete();
    expect(\App\Models\BadgeLibraryVersion::find($privateVersion->id))->toBeNull();
    \Illuminate\Support\Facades\Storage::disk($privateArtwork['background_disk'])->assertMissing($privateArtwork['background_path']);
    \Illuminate\Support\Facades\Storage::disk($current->backSettings()['background_disk'])->assertMissing($current->backSettings()['background_path']);
    \Illuminate\Support\Facades\Storage::disk($revision['background_disk'])->assertMissing($revision['background_path']);
    \Illuminate\Support\Facades\Storage::disk($version->snapshotSettings()['background_disk'])->assertExists($version->snapshotSettings()['background_path']);
});

test('post-commit artwork cleanup failure cannot fail an applied design or delete its new copies', function (): void {
    $disk = \Illuminate\Support\Facades\Storage::fake('local');
    $file = \Illuminate\Http\UploadedFile::fake()->image('existing.png', 540, 850);
    $disk->put('old-library-artwork.png', file_get_contents($file->getRealPath()));
    $this->design->update(['background_disk' => 'local', 'background_path' => 'old-library-artwork.png']);
    $version = \App\Models\BadgeLibraryDesign::where('name', 'Essential')->firstOrFail()->versions()->firstOrFail();
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('delete')->andThrow(new RuntimeException('Temporary storage deletion outage'));
    \Illuminate\Support\Facades\Storage::set('local', $proxy);
    $this->postJson($this->base.'/apply', ['version_id' => $version->id, 'expected_version' => 1])->assertOk();
    expect($this->design->fresh()->design_version)->toBe(2);
    $revision = \App\Models\BadgeDesignRevision::where('event_id', $this->event->id)->firstOrFail()->snapshotSettings();
    expect($disk->exists($revision['background_path']))->toBeTrue();
});

test('valid numeric form strings work for saving and applying a design', function (): void {
    $saved = $this->postJson($this->base, ['name' => 'Form values', 'scope' => 'event', 'expected_version' => '1'])->assertCreated();
    $this->postJson($this->base.'/apply', ['version_id' => $saved->json('version.id'), 'expected_version' => '1'])->assertOk()->assertJsonPath('template.design_version', 2);
});

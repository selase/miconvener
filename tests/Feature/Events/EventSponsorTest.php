<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventSponsor;
use App\Models\EventSponsorDeliverable;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function sponsorHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('host can add a sponsor with a logo, and it appears on the public event page grouped by tier', function () {
    Storage::fake('public');
    [$tenant, $user] = sponsorHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/events/{$event->id}/sponsors", [
        'name' => 'Oracle Health',
        'tier' => 'headline',
        'booth' => 'A1',
        'amount' => 50_000,
        'logo' => UploadedFile::fake()->image('logo.png'),
    ], ['HTTP_HOST' => $host]);

    $response->assertCreated();
    expect($response->json('logo_url'))->not->toBeNull();

    $public = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host]);
    $public->assertOk();
    $public->assertInertia(fn ($page) => $page
        ->where('event.sponsors.0.name', 'Oracle Health')
        ->where('event.sponsors.0.tier', 'headline')
    );

    // Contact and financial details never reach the public payload.
    $public->assertInertia(fn ($page) => $page->missing('event.sponsors.0.contact_email'));
});

test('host can manage a sponsor deliverable checklist', function () {
    [$tenant, $user] = sponsorHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $sponsor = EventSponsor::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $store = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sponsors/{$sponsor->id}/deliverables", [
        'description' => 'Logo on the closing plenary slide',
    ], ['HTTP_HOST' => $host]);
    $store->assertCreated();

    $deliverable = EventSponsorDeliverable::where('sponsor_id', $sponsor->id)->firstOrFail();
    expect($deliverable->is_done)->toBeFalse();

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/sponsors/{$sponsor->id}/deliverables/{$deliverable->id}", [
        'is_done' => true,
    ], ['HTTP_HOST' => $host])->assertOk();

    expect($deliverable->fresh()->is_done)->toBeTrue();
});

test('the sponsor deliverables export includes every sponsor and their status', function () {
    [$tenant, $user] = sponsorHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $sponsor = EventSponsor::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Nyaho Medical']);
    EventSponsorDeliverable::factory()->create(['tenant_id' => $tenant->id, 'sponsor_id' => $sponsor->id, 'description' => 'Insert in the delegate bag', 'is_done' => true]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/sponsors/export", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('Nyaho Medical');
    expect($content)->toContain('Insert in the delegate bag');
    expect($content)->toContain('Done');
});

test('a host without update event permission cannot add a sponsor', function () {
    [$tenant] = sponsorHost();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $tenant->users()->attach($user->id); // no role assigned

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sponsors", [
        'name' => 'Test Sponsor',
        'tier' => 'partner',
    ], ['HTTP_HOST' => $host])->assertForbidden();
});

<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use App\Models\Speaker;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function speakerPortalHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('a valid token reaches the speaker portal with only their own sessions', function () {
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abena Owusu']);
    $token = Str::random(40);
    $pivot = EventSpeaker::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'speaker_id' => $speaker->id, 'portal_token' => $token]);
    $session = EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Their session']);
    $session->speakers()->attach($speaker->id, ['id' => (string) Str::uuid()]);
    EventSession::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Someone else\'s session']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->get("http://{$host}/e/{$event->slug}/speaker-portal/{$token}", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('speaker.name', 'Abena Owusu')
        ->where('sessions.0.title', 'Their session')
        ->has('sessions', 1)
    );
});

test('an unknown token 404s', function () {
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->get("http://{$host}/e/{$event->slug}/speaker-portal/not-a-real-token", ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('a speaker can confirm attendance through their portal', function () {
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id]);
    $token = Str::random(40);
    EventSpeaker::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'speaker_id' => $speaker->id, 'portal_token' => $token]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->postJson("http://{$host}/e/{$event->slug}/speaker-portal/{$token}/confirm", ['attending' => true], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['is_confirmed' => true]);

    $pivot = EventSpeaker::where('portal_token', $token)->firstOrFail();
    expect($pivot->is_confirmed)->toBeTrue();
});

test('uploaded slides become a downloadable material automatically', function () {
    Storage::fake('public');
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Kwame Asante']);
    $token = Str::random(40);
    EventSpeaker::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'speaker_id' => $speaker->id, 'portal_token' => $token]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->post("http://{$host}/e/{$event->slug}/speaker-portal/{$token}/slides", [
        'slides' => UploadedFile::fake()->create('slides.pdf', 500, 'application/pdf'),
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();

    $material = EventMaterial::where('event_id', $event->id)->firstOrFail();
    expect($material->title)->toBe('Kwame Asante — Slides');

    $pivot = EventSpeaker::where('portal_token', $token)->firstOrFail();
    expect($pivot->slides_material_id)->toBe($material->id);
});

test('a host can fetch a speaker portal link, generating a token if one is missing', function () {
    [$tenant, $user] = speakerPortalHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id]);
    EventSpeaker::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'speaker_id' => $speaker->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/speakers/{$speaker->id}/portal-link", ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($response->json('portal_url'))->toContain('/speaker-portal/');
    expect(EventSpeaker::where('event_id', $event->id)->where('speaker_id', $speaker->id)->first()->portal_token)->not->toBeNull();
});

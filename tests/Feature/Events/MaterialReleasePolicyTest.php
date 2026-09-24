<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use App\Models\Speaker;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\MaterialReleasePolicy;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Storage::fake('public');
});

test('explicit release_at always wins over provenance or event speaker policy', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'health-org', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'speaker_slide_policy' => Event::SPEAKER_POLICY_BEFORE,
    ]);

    $policy = app(MaterialReleasePolicy::class);

    // Organiser material with future release_at is withheld
    $futureOrganiserMaterial = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provenance' => EventMaterial::PROVENANCE_ORGANIZER,
        'release_at' => now()->addHours(2),
    ]);
    expect($policy->isReleased($futureOrganiserMaterial))->toBeFalse()
        ->and($futureOrganiserMaterial->isReleased())->toBeFalse();

    // Organiser material with past release_at is released
    $pastOrganiserMaterial = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provenance' => EventMaterial::PROVENANCE_ORGANIZER,
        'release_at' => now()->subHours(2),
    ]);
    expect($policy->isReleased($pastOrganiserMaterial))->toBeTrue()
        ->and($pastOrganiserMaterial->isReleased())->toBeTrue();

    // Speaker material with future release_at is withheld even when event policy is 'before'
    $futureSpeakerMaterial = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provenance' => EventMaterial::PROVENANCE_SPEAKER,
        'release_at' => now()->addDay(),
    ]);
    expect($policy->isReleased($futureSpeakerMaterial))->toBeFalse()
        ->and($futureSpeakerMaterial->isReleased())->toBeFalse();

    // Speaker material with past release_at is released even with 'after' event policy
    $event->update(['speaker_slide_policy' => Event::SPEAKER_POLICY_AFTER]);
    $pastSpeakerMaterial = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provenance' => EventMaterial::PROVENANCE_SPEAKER,
        'release_at' => now()->subDay(),
    ]);
    expect($policy->isReleased($pastSpeakerMaterial))->toBeTrue()
        ->and($pastSpeakerMaterial->isReleased())->toBeTrue();
});

test('undated organiser material is immediately released', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'health-org', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $material = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provenance' => EventMaterial::PROVENANCE_ORGANIZER,
        'release_at' => null,
    ]);

    expect($material->isReleased())->toBeTrue()
        ->and($material->isOrganizerMaterial())->toBeTrue()
        ->and($material->isSpeakerDeck())->toBeFalse();
});

test('speaker policy before releases decks immediately for session-linked and sessionless speakers', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'health-org', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'speaker_slide_policy' => Event::SPEAKER_POLICY_BEFORE,
    ]);

    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id]);
    $deck = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provenance' => EventMaterial::PROVENANCE_SPEAKER,
        'release_at' => null,
    ]);

    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
        'slides_material_id' => $deck->id,
    ]);

    // Sessionless deck under 'before' policy is released
    expect($deck->isReleased())->toBeTrue();

    // With future session, still released under 'before'
    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(1),
    ]);
    $session->speakers()->attach($speaker->id, ['id' => (string) Str::uuid()]);

    expect($deck->fresh()->isReleased())->toBeTrue();
});

test('speaker policy during releases deck at first linked session start and withholds sessionless decks', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'health-org', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'speaker_slide_policy' => Event::SPEAKER_POLICY_DURING,
    ]);

    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id]);
    $deck = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provenance' => EventMaterial::PROVENANCE_SPEAKER,
        'release_at' => null,
    ]);

    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
        'slides_material_id' => $deck->id,
    ]);

    // Sessionless deck stays withheld
    expect($deck->isReleased())->toBeFalse();

    // Link two sessions: Session 1 (starts in 2 hours), Session 2 (starts in 5 hours)
    $session1 = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(3),
    ]);
    $session1->speakers()->attach($speaker->id, ['id' => (string) Str::uuid()]);

    $session2 = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->addHours(5),
        'ends_at' => now()->addHours(6),
    ]);
    $session2->speakers()->attach($speaker->id, ['id' => (string) Str::uuid()]);

    $policy = app(MaterialReleasePolicy::class);

    // Before session 1 starts: withheld
    expect($policy->isReleased($deck, now()))->toBeFalse();
    expect($policy->isReleased($deck, now()->addHour()))->toBeFalse();

    // At session 1 start: released
    expect($policy->isReleased($deck, now()->addHours(2)))->toBeTrue();

    // During and after: released
    expect($policy->isReleased($deck, now()->addHours(3)))->toBeTrue();
});

test('speaker policy after releases deck at last linked session end and withholds sessionless decks', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'health-org', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'speaker_slide_policy' => Event::SPEAKER_POLICY_AFTER,
    ]);

    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id]);
    $deck = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provenance' => EventMaterial::PROVENANCE_SPEAKER,
        'release_at' => null,
    ]);

    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
        'slides_material_id' => $deck->id,
    ]);

    // Sessionless deck stays withheld
    expect($deck->isReleased())->toBeFalse();

    // Session 1: ends at +2h; Session 2: ends at +5h
    $session1 = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
    ]);
    $session1->speakers()->attach($speaker->id, ['id' => (string) Str::uuid()]);

    $session2 = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->addHours(4),
        'ends_at' => now()->addHours(5),
    ]);
    $session2->speakers()->attach($speaker->id, ['id' => (string) Str::uuid()]);

    $policy = app(MaterialReleasePolicy::class);

    // During session 1: withheld
    expect($policy->isReleased($deck, now()->addHours(1)->addMinutes(30)))->toBeFalse();

    // After session 1 but before session 2 ends: withheld
    expect($policy->isReleased($deck, now()->addHours(3)))->toBeFalse();

    // At last session end (+5h): released
    expect($policy->isReleased($deck, now()->addHours(5)))->toBeTrue();
    expect($policy->isReleased($deck, now()->addHours(6)))->toBeTrue();
});

test('releasedMaterialsForSession deduplicates shared decks and excludes unreleased materials', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'health-org', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'speaker_slide_policy' => Event::SPEAKER_POLICY_BEFORE,
    ]);

    $session = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
    ]);

    // 1. Organiser session handout (released)
    $handout = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'title' => 'Session Handout',
        'provenance' => EventMaterial::PROVENANCE_ORGANIZER,
        'release_at' => null,
    ]);

    // 2. Organiser session handout with future release_at (withheld)
    $withheldHandout = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'session_id' => $session->id,
        'title' => 'Secret Answers',
        'provenance' => EventMaterial::PROVENANCE_ORGANIZER,
        'release_at' => now()->addDays(5),
    ]);

    // 3. Two co-presenters in the session sharing the SAME slide deck
    $speaker1 = Speaker::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dr. Alice']);
    $speaker2 = Speaker::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dr. Bob']);
    $sharedDeck = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Joint Presentation Slides',
        'provenance' => EventMaterial::PROVENANCE_SPEAKER,
        'release_at' => null,
    ]);

    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker1->id,
        'slides_material_id' => $sharedDeck->id,
    ]);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker2->id,
        'slides_material_id' => $sharedDeck->id,
    ]);

    $session->speakers()->attach($speaker1->id, ['id' => (string) Str::uuid()]);
    $session->speakers()->attach($speaker2->id, ['id' => (string) Str::uuid()]);

    $policy = app(MaterialReleasePolicy::class);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $materials = $policy->releasedMaterialsForSession($session, $registration);

    // Exactly 2 materials: Handout and SharedDeck (deduplicated!)
    expect($materials)->toHaveCount(2);

    $ids = collect($materials)->pluck('id')->all();
    expect($ids)->toContain($handout->id)
        ->and($ids)->toContain($sharedDeck->id)
        ->and($ids)->not->toContain($withheldHandout->id);
});

test('organiser can update speaker slide release policy via PATCH endpoint with permission check', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme-corp', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'speaker_slide_policy' => Event::SPEAKER_POLICY_AFTER,
    ]);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme-corp.{$baseDomain}";

    // Update to 'during'
    $response = $this->actingAs($user)->patch("http://{$host}/events/{$event->id}/materials/speaker-policy", [
        'speaker_slide_policy' => 'during',
    ], ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJson([
            'speaker_slide_policy' => 'during',
            'message' => 'Speaker slide release policy updated.',
        ]);

    expect($event->fresh()->speaker_slide_policy)->toBe(Event::SPEAKER_POLICY_DURING);

    // Invalid policy value returns 422
    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/materials/speaker-policy", [
        'speaker_slide_policy' => 'whenever',
    ], ['HTTP_HOST' => $host])->assertStatus(422);

    // Unauthorized user receives 403
    $regularUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($regularUser->id);
    $this->actingAs($regularUser)->patchJson("http://{$host}/events/{$event->id}/materials/speaker-policy", [
        'speaker_slide_policy' => 'before',
    ], ['HTTP_HOST' => $host])->assertForbidden();
});

test('attendee workspace show passes released session materials to My Day and honors download gating', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'summit-org', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'speaker_slide_policy' => Event::SPEAKER_POLICY_AFTER,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addHours(4),
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Session 1 is over (past)
    $endedSession = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'title' => 'Morning Keynote',
    ]);

    // Session 2 is currently ongoing
    $ongoingSession = EventSession::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'starts_at' => now()->subMinutes(30),
        'ends_at' => now()->addHours(1),
        'title' => 'Afternoon Panel',
    ]);

    $speaker1 = Speaker::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Prof. Kwame']);
    $speaker2 = Speaker::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dr. Mensah']);

    $keynoteDeck = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Keynote Slides',
        'provenance' => EventMaterial::PROVENANCE_SPEAKER,
        'file_path' => 'event-materials/keynote.pdf',
        'release_at' => null,
        'download_limit' => 2,
    ]);
    Storage::disk('public')->put($keynoteDeck->file_path, 'fake-keynote-bytes');

    $panelDeck = EventMaterial::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Panel Discussion Slides',
        'provenance' => EventMaterial::PROVENANCE_SPEAKER,
        'file_path' => 'event-materials/panel.pdf',
        'release_at' => null,
        'download_limit' => 2,
    ]);
    Storage::disk('public')->put($panelDeck->file_path, 'fake-panel-bytes');

    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker1->id,
        'slides_material_id' => $keynoteDeck->id,
    ]);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker2->id,
        'slides_material_id' => $panelDeck->id,
    ]);

    $endedSession->speakers()->attach($speaker1->id, ['id' => (string) Str::uuid()]);
    $ongoingSession->speakers()->attach($speaker2->id, ['id' => (string) Str::uuid()]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    // Authenticate attendee on platform
    $response = $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'attendee@example.com',
            'verified_at' => now()->timestamp,
            'expires_at' => now()->addHours(12)->timestamp,
        ],
    ])->get("http://{$baseDomain}/my/events/{$registration->id}", ['HTTP_HOST' => $baseDomain]);

    $response->assertOk();

    // Check Inertia props: keynote deck is released (session ended); panel deck is withheld (session ongoing under 'after' policy)
    $response->assertInertia(fn ($page) => $page
        ->component('Public/Events/AttendeePortal/Portal')
        ->has('event.sessions', 2)
        ->where('event.sessions.0.id', $endedSession->id)
        ->has('event.sessions.0.materials', 1)
        ->where('event.sessions.0.materials.0.id', $keynoteDeck->id)
        ->where('event.sessions.0.materials.0.provenance', 'speaker')
        ->where('event.sessions.1.id', $ongoingSession->id)
        ->has('event.sessions.1.materials', 0)
    );

    // Downloading released keynote deck succeeds
    $downloadUrl = route('attendee.my.events.materials.download', [
        'registration' => $registration->id,
        'material' => $keynoteDeck->id,
    ]);
    expect($this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'attendee@example.com',
            'verified_at' => now()->timestamp,
            'expires_at' => now()->addHours(12)->timestamp,
        ],
    ])->get($downloadUrl, ['HTTP_HOST' => $baseDomain])->assertOk()->streamedContent())->toBe('fake-keynote-bytes');

    // Downloading unreleased panel deck returns 403 Forbidden
    $unreleasedUrl = route('attendee.my.events.materials.download', [
        'registration' => $registration->id,
        'material' => $panelDeck->id,
    ]);
    $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'attendee@example.com',
            'verified_at' => now()->timestamp,
            'expires_at' => now()->addHours(12)->timestamp,
        ],
    ])->get($unreleasedUrl, ['HTTP_HOST' => $baseDomain])->assertStatus(403);
});

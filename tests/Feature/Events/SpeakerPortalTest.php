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
use Illuminate\Support\Facades\Mail;
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

test('a speaker cannot access portal if event is unpublished draft', function () {
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_DRAFT]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id]);
    $token = Str::random(40);
    EventSpeaker::create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'speaker_id' => $speaker->id, 'portal_token' => $token]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->get("http://{$host}/e/{$event->slug}/speaker-portal/{$token}", ['HTTP_HOST' => $host])
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
    expect($pivot->is_confirmed)->toBeTrue()
        ->and($pivot->confirmed_at)->not->toBeNull();
});

test('a speaker can decline attendance through their portal clearing confirmed_at', function () {
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id]);
    $token = Str::random(40);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
        'portal_token' => $token,
        'is_confirmed' => true,
        'confirmed_at' => now(),
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->postJson("http://{$host}/e/{$event->slug}/speaker-portal/{$token}/confirm", ['attending' => false], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['is_confirmed' => false]);

    $pivot = EventSpeaker::where('portal_token', $token)->firstOrFail();
    expect($pivot->is_confirmed)->toBeFalse()
        ->and($pivot->confirmed_at)->toBeNull();
});

test('uploaded slides become a downloadable material automatically and replacement purges old file', function () {
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
    $firstPath = $material->file_path;
    Storage::disk('public')->assertExists($firstPath);

    // Upload replacement deck
    $response2 = $this->post("http://{$host}/e/{$event->slug}/speaker-portal/{$token}/slides", [
        'slides' => UploadedFile::fake()->create('slides_v2.pdf', 600, 'application/pdf'),
    ], ['HTTP_HOST' => $host]);

    $response2->assertOk();
    $material->refresh();
    expect($material->file_path)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($material->file_path);
    Storage::disk('public')->assertMissing($firstPath);
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

test('a host can create a speaker with headshot photo and social links in the console', function () {
    Storage::fake('public');
    [$tenant, $user] = speakerPortalHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->post("http://{$host}/speakers", [
        'name' => 'Dr. Ama Serwaa',
        'email' => 'ama.serwaa@example.com',
        'title' => 'Chief Scientist',
        'organization' => 'African Genome Institute',
        'bio' => 'Leading genomic researcher.',
        'website_url' => 'https://amaserwaa.org',
        'linkedin_url' => 'https://linkedin.com/in/amaserwaa',
        'twitter_url' => 'https://x.com/amaserwaa',
        'photo' => UploadedFile::fake()->image('headshot.jpg', 400, 400),
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $speakerId = $response->json('id');
    expect($speakerId)->not->toBeNull();

    $speaker = Speaker::where('id', $speakerId)->firstOrFail();
    expect($speaker->name)->toBe('Dr. Ama Serwaa')
        ->and($speaker->email)->toBe('ama.serwaa@example.com')
        ->and($speaker->website_url)->toBe('https://amaserwaa.org')
        ->and($speaker->photo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($speaker->photo_path);
});

test('a host can send an invitation email to a speaker with credit metering', function () {
    Mail::fake();
    [$tenant, $user] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Prof. Kofi Mensah',
        'email' => 'kmensah@example.edu',
    ]);
    $pivot = EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/speakers/{$speaker->id}/invite", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $pivot->refresh();
    expect($pivot->last_invited_at)->not->toBeNull()
        ->and($pivot->portal_token)->not->toBeNull();

    Mail::assertSent(\App\Mail\Events\SpeakerPortalInvitationMail::class, function ($mail) {
        return $mail->hasTo('kmensah@example.edu');
    });

    $usage = app(\App\Services\Tenancy\FeatureMeteringService::class)->getUsage($tenant, 'email_credits');
    expect($usage)->toBeGreaterThan(0);
});

test('inviting a speaker without an email address is rejected with 422', function () {
    [$tenant, $user] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::create([
        'tenant_id' => $tenant->id,
        'name' => 'Email-less Speaker',
        'email' => null,
    ]);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/speakers/{$speaker->id}/invite", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    $response->assertJson(['message' => 'Speaker does not have an email address.']);
});

test('a speaker can update their profile information and social links via self-service portal', function () {
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Original Name',
        'bio' => 'Old bio',
    ]);
    $token = Str::random(40);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
        'portal_token' => $token,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->postJson("http://{$host}/e/{$event->slug}/speaker-portal/{$token}/profile", [
        'name' => 'Updated Speaker Name',
        'title' => 'Distinguished Fellow',
        'organization' => 'Global Health Institute',
        'bio' => 'Updated rich biography.',
        'website_url' => 'https://speaker.example.com',
        'linkedin_url' => 'https://linkedin.com/in/updated',
        'twitter_url' => 'https://x.com/updated',
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $speaker->refresh();

    expect($speaker->name)->toBe('Updated Speaker Name')
        ->and($speaker->title)->toBe('Distinguished Fellow')
        ->and($speaker->organization)->toBe('Global Health Institute')
        ->and($speaker->bio)->toBe('Updated rich biography.')
        ->and($speaker->website_url)->toBe('https://speaker.example.com')
        ->and($speaker->linkedin_url)->toBe('https://linkedin.com/in/updated')
        ->and($speaker->twitter_url)->toBe('https://x.com/updated');
});

test('a speaker can upload and replace their headshot photo via self-service portal', function () {
    Storage::fake('public');
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Photo Test Speaker']);
    $token = Str::random(40);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
        'portal_token' => $token,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->post("http://{$host}/e/{$event->slug}/speaker-portal/{$token}/photo", [
        'photo' => UploadedFile::fake()->image('self_portrait.png', 500, 500),
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $speaker->refresh();

    expect($speaker->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($speaker->photo_path);
    $firstPath = $speaker->photo_path;

    // Upload a replacement photo and ensure old one is deleted
    $response2 = $this->post("http://{$host}/e/{$event->slug}/speaker-portal/{$token}/photo", [
        'photo' => UploadedFile::fake()->image('replacement.jpg', 600, 600),
    ], ['HTTP_HOST' => $host]);

    $response2->assertOk();
    $speaker->refresh();

    expect($speaker->photo_path)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($speaker->photo_path);
    Storage::disk('public')->assertMissing($firstPath);
});

test('tenant isolation: tenant A cannot invite or modify tenant B speaker', function () {
    [$tenantA, $userA] = speakerPortalHost();
    $tenantB = Tenant::factory()->create(['slug' => 'other-tenant', 'isolation_mode' => 'shared']);
    $eventB = Event::factory()->published()->create(['tenant_id' => $tenantB->id]);
    $speakerB = Speaker::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'b@example.com']);
    EventSpeaker::create([
        'tenant_id' => $tenantB->id,
        'event_id' => $eventB->id,
        'speaker_id' => $speakerB->id,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $hostA = "acme.{$baseDomain}";

    // Attempt to invite tenant B's speaker through tenant A's host
    $response = $this->actingAs($userA)->postJson("http://{$hostA}/events/{$eventB->id}/speakers/{$speakerB->id}/invite", [], ['HTTP_HOST' => $hostA]);
    $response->assertNotFound();
});

test('unauthorized or guest attempts to invite or create speaker are rejected', function () {
    [$tenant] = speakerPortalHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $speaker = Speaker::factory()->create(['tenant_id' => $tenant->id, 'email' => 'speaker@example.com']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    // Guest cannot invite
    $this->postJson("http://{$host}/events/{$event->id}/speakers/{$speaker->id}/invite")
        ->assertUnauthorized();

    // Guest cannot create speaker
    $this->postJson("http://{$host}/speakers", ['name' => 'Dr Hacker', 'email' => 'hacker@example.com'])
        ->assertUnauthorized();
});

<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSpeaker;
use App\Models\Speaker;
use App\Models\Tenant;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a speaker cannot be created without an address', function (): void {
    [$tenant, $user] = eventHost('speaker-email');
    $host = eventSubdomainHost('speaker-email');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/speakers", [
            'name' => 'Dr Ama Serwaa',
        ], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('a speaker is found by address, however it was typed', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'speaker-match', 'isolation_mode' => 'shared']);
    $speaker = Speaker::create([
        'tenant_id' => $tenant->id,
        'name' => 'Dr Ama Serwaa',
        'email' => 'Ama.Serwaa@Example.com',
    ]);

    expect(Speaker::forEmail('  ama.serwaa@example.com ')->first()?->id)->toBe($speaker->id);
});

test('a legacy speaker with no address neither breaks nor hides', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'speaker-legacy', 'isolation_mode' => 'shared']);
    $speaker = Speaker::create(['tenant_id' => $tenant->id, 'name' => 'Nameless Legacy']);

    // Recognition must not explode on a null, and the row must stay visible so
    // an organiser can fix it rather than discover it at an event.
    expect(Speaker::forEmail('anything@example.com')->count())->toBe(0)
        ->and($speaker->fresh()->email)->toBeNull()
        ->and($speaker->needsEmail())->toBeTrue();
});

test('a speaker sees that they are speaking, from the ticket they already hold', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'speaker-portal', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'ama@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $speaker = Speaker::create([
        'tenant_id' => $tenant->id,
        'name' => 'Dr Ama Serwaa',
        'email' => 'Ama@Example.com',
        'title' => 'Head of Digital Health',
    ]);
    EventSpeaker::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'speaker_id' => $speaker->id,
    ]);

    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor('ama@example.com'))
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('speaker.name', 'Dr Ama Serwaa')
            ->where('speaker.title', 'Head of Digital Health'));
});

test('an attendee who is not speaking is told nothing about speakers', function (): void {
    [$tenant, $event, $registration] = virtualEventScenario('speaker-not');

    $host = app(TenantHostMatcher::class)->baseDomain();

    $this->withSession(proofFor($registration->email))
        ->get("http://{$host}/my/events/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('speaker', null));
});

<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventSession;
use App\Models\Speaker;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('organizer can create sessions with scientific types and link to accepted abstracts', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'science-summit-2026',
    ]);

    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-ORAL1',
        'title' => 'Translational Oncology Breakthroughs',
        'track' => 'Oncology',
        'presentation_preference' => 'oral',
        'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
    ]);

    $speaker = Speaker::create([
        'tenant_id' => $tenant->id,
        'name' => 'Dr. Araba Mensah',
        'title' => 'Chief Oncologist',
        'organization' => 'National Cancer Center',
    ]);

    // Create oral presentation session linking the abstract and speaker
    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/sessions", [
            'title' => 'Oral Presentations: Breakthroughs in Oncology',
            'starts_at' => '2026-10-15 09:00:00',
            'ends_at' => '2026-10-15 10:30:00',
            'location' => 'Auditorium A',
            'track' => 'Oncology',
            'type' => EventSession::TYPE_ORAL_PRESENTATION,
            'abstract_id' => $abstract->id,
            'speaker_ids' => [$speaker->id],
            'speaker_roles' => [
                $speaker->id => 'oral_presenter',
            ],
            'capacity' => 150,
            'sort_order' => 1,
        ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $sessionId = $response->json('id');

    $session = EventSession::where('id', $sessionId)->first();
    expect($session->type)->toBe('oral_presentation');
    expect($session->abstract_id)->toBe($abstract->id);
    expect($session->speakers)->toHaveCount(1);
    expect($session->speakers->first()->pivot->role)->toBe('oral_presenter');
});

test('organizer can export official conference abstract book as PDF', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'annual-academic-2026',
    ]);

    $abs1 = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-P1',
        'title' => 'Cardiovascular Disease Trends in West Africa',
        'track' => 'Cardiology',
        'presentation_preference' => 'oral',
        'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
        'structured_abstract' => [
            'background' => 'Regional disease trends show increasing hypertension.',
            'methods' => 'Cross-sectional survey of 5,000 households.',
            'results' => 'Prevalence of hypertension was 32.4%.',
            'conclusion' => 'Early screening interventions significantly reduce mortality.',
        ],
        'keywords' => ['Cardiology', 'Hypertension', 'Epidemiology'],
    ]);

    $abs1->authors()->create([
        'first_name' => 'Kojo',
        'last_name' => 'Antwi',
        'email' => 'kantwi@cardio.gh',
        'affiliation' => 'University of Ghana Medical School',
        'is_presenting' => true,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}/reports/abstract-book", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('annual-academic-2026-abstract-book.pdf');
    expect(mb_strlen($response->getContent()))->toBeGreaterThan(1000); // Valid non-empty PDF binary stream
});

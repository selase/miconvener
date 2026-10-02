<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventSession;
use App\Services\Events\EventLexicon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('EventLexicon resolves tailored vocabulary across all supported archetypes', function (): void {
    // 1. Faith / Church
    $faith = EventLexicon::forCategory(Event::CATEGORY_FAITH);
    expect($faith['category'])->toBe(Event::CATEGORY_FAITH)
        ->and($faith['contributions_title'])->toBe('Tithes & Offerings')
        ->and($faith['contributions_tab_label'])->toBe('Tithes & Offerings')
        ->and($faith['contributions_cta_label'])->toBe('Give / Tithe')
        ->and($faith['wall_title'])->toBe('Blessings & Prayer Wall')
        ->and($faith['notes_action_label'])->toBe('Sermon Notes')
        ->and($faith['session_types'])->toHaveKeys([
            EventSession::TYPE_SERVICE,
            EventSession::TYPE_SUNDAY_SCHOOL,
            EventSession::TYPE_BIBLE_STUDY,
            EventSession::TYPE_PRAYER,
        ]);

    // 2. Memorial / Funeral
    $memorial = EventLexicon::forCategory(Event::CATEGORY_MEMORIAL);
    expect($memorial['category'])->toBe(Event::CATEGORY_MEMORIAL)
        ->and($memorial['contributions_title'])->toBe('Funeral Donations & Support')
        ->and($memorial['contributions_tab_label'])->toBe('Tributes & Condolences')
        ->and($memorial['contributions_cta_label'])->toBe('Contribute / Leave Tribute')
        ->and($memorial['wall_title'])->toBe('Tribute & Condolence Wall')
        ->and($memorial['notes_action_label'])->toBe('Order of Service')
        ->and($memorial['session_types'])->toHaveKeys([
            EventSession::TYPE_PRE_BURIAL,
            EventSession::TYPE_BURIAL_SERVICE,
            EventSession::TYPE_THANKSGIVING_SERVICE,
            EventSession::TYPE_REPAST,
        ]);

    // 3. Academic / Course & Lecture
    $academic = EventLexicon::forCategory(Event::CATEGORY_ACADEMIC);
    expect($academic['category'])->toBe(Event::CATEGORY_ACADEMIC)
        ->and($academic['contributions_title'])->toBe('Department & Class Fund')
        ->and($academic['contributions_tab_label'])->toBe('Class Giving')
        ->and($academic['contributions_cta_label'])->toBe('Contribute to Fund')
        ->and($academic['wall_title'])->toBe('Student & Alumni Message Board')
        ->and($academic['notes_action_label'])->toBe('Lecture Notes')
        ->and($academic['session_types'])->toHaveKeys([
            EventSession::TYPE_LECTURE,
            EventSession::TYPE_LAB,
            EventSession::TYPE_SEMINAR,
            EventSession::TYPE_TUTORIAL,
            EventSession::TYPE_OFFICE_HOURS,
        ]);

    // 4. Fundraiser / Nonprofit
    $fundraiser = EventLexicon::forCategory(Event::CATEGORY_FUNDRAISER);
    expect($fundraiser['category'])->toBe(Event::CATEGORY_FUNDRAISER)
        ->and($fundraiser['contributions_title'])->toBe('Donations & Pledges')
        ->and($fundraiser['contributions_tab_label'])->toBe('Donate')
        ->and($fundraiser['contributions_cta_label'])->toBe('Donate to Cause')
        ->and($fundraiser['wall_title'])->toBe('Donor Wall & Solidarity Messages')
        ->and($fundraiser['notes_action_label'])->toBe('Campaign Notes');

    // 5. Conference / Corporate Summit
    $conference = EventLexicon::forCategory(Event::CATEGORY_CONFERENCE);
    expect($conference['category'])->toBe(Event::CATEGORY_CONFERENCE)
        ->and($conference['contributions_title'])->toBe('Community Sponsorship')
        ->and($conference['contributions_tab_label'])->toBe('Support Event')
        ->and($conference['contributions_cta_label'])->toBe('Contribute Support')
        ->and($conference['wall_title'])->toBe('Attendee Message Board')
        ->and($conference['notes_action_label'])->toBe('Session Notes')
        ->and($conference['session_types'])->toHaveKeys([
            EventSession::TYPE_KEYNOTE,
            EventSession::TYPE_PLENARY,
            EventSession::TYPE_WORKSHOP,
            EventSession::TYPE_BREAKOUT,
        ]);

    // 6. General / Fallback
    $general = EventLexicon::forCategory(Event::CATEGORY_GENERAL);
    expect($general['category'])->toBe(Event::CATEGORY_GENERAL)
        ->and($general['contributions_title'])->toBe('Voluntary Contributions')
        ->and($general['contributions_tab_label'])->toBe('Support')
        ->and($general['contributions_cta_label'])->toBe('Contribute Support')
        ->and($general['wall_title'])->toBe('Community Wall')
        ->and($general['notes_action_label'])->toBe('Session Notes');

    // Null or unknown string falls back safely to General
    $fallback = EventLexicon::forCategory('unknown_custom_category');
    expect($fallback['category'])->toBe(Event::CATEGORY_GENERAL)
        ->and($fallback['contributions_title'])->toBe('Voluntary Contributions');
});

test('Event model helpers identify archetypes accurately', function (): void {
    [$tenant] = eventHost('lexicon-test');

    $event = new Event([
        'tenant_id' => $tenant->id,
        'event_category' => Event::CATEGORY_FAITH,
    ]);

    expect($event->isFaith())->toBeTrue()
        ->and($event->isMemorial())->toBeFalse()
        ->and($event->isAcademic())->toBeFalse()
        ->and($event->isFundraiser())->toBeFalse()
        ->and($event->isConference())->toBeFalse();

    $event->event_category = Event::CATEGORY_MEMORIAL;
    expect($event->isMemorial())->toBeTrue()
        ->and($event->isFaith())->toBeFalse();

    $event->event_category = Event::CATEGORY_ACADEMIC;
    expect($event->isAcademic())->toBeTrue();

    $event->event_category = Event::CATEGORY_FUNDRAISER;
    expect($event->isFundraiser())->toBeTrue();

    $event->event_category = Event::CATEGORY_CONFERENCE;
    expect($event->isConference())->toBeTrue();

    expect($event->lexicon()['category'])->toBe(Event::CATEGORY_CONFERENCE);
});

test('Tenant EventController store validates event_category and rejects invalid values', function (): void {
    [$tenant, $user] = eventHost('church-accra');
    $host = eventSubdomainHost('church-accra');

    $startsAt = CarbonImmutable::now()->addDays(2)->setTime(10, 0)->toIso8601String();
    $endsAt = CarbonImmutable::now()->addDays(2)->setTime(13, 0)->toIso8601String();

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Invalid Category Event',
        'event_category' => 'unsupported_alien_category',
        'status' => Event::STATUS_DRAFT,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Accra Central',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors(['event_category']);
    expect(Event::where('name', 'Invalid Category Event')->exists())->toBeFalse();
});

test('Tenant EventController store assigns category-appropriate default contribution title when omitted', function (): void {
    [$tenant, $user] = eventHost('faith-chapel');
    $host = eventSubdomainHost('faith-chapel');

    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(9, 0)->toIso8601String();
    $endsAt = CarbonImmutable::now()->addDays(3)->setTime(12, 0)->toIso8601String();

    // 1. Faith Event without contribution_title
    $resFaith = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Victory Chapel Sunday Service',
        'event_category' => Event::CATEGORY_FAITH,
        'status' => Event::STATUS_DRAFT,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Victory Chapel Sanctuary',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $resFaith->assertRedirect();
    $faithEvent = Event::where('name', 'Victory Chapel Sunday Service')->first();
    expect($faithEvent)->not->toBeNull()
        ->and($faithEvent->event_category)->toBe(Event::CATEGORY_FAITH)
        ->and($faithEvent->contribution_title)->toBe('Tithes & Offerings');

    // 2. Memorial Event without contribution_title
    $resMem = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Celebration of Life - Late Nana Mensah',
        'event_category' => Event::CATEGORY_MEMORIAL,
        'status' => Event::STATUS_DRAFT,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Transitions Funeral Home',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $resMem->assertRedirect();
    $memEvent = Event::where('name', 'Celebration of Life - Late Nana Mensah')->first();
    expect($memEvent)->not->toBeNull()
        ->and($memEvent->event_category)->toBe(Event::CATEGORY_MEMORIAL)
        ->and($memEvent->contribution_title)->toBe('Funeral Donations & Support');
});

test('Tenant EventController store honors explicitly provided contribution title override', function (): void {
    [$tenant, $user] = eventHost('academic-dept');
    $host = eventSubdomainHost('academic-dept');

    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(14, 0)->toIso8601String();
    $endsAt = CarbonImmutable::now()->addDays(3)->setTime(16, 0)->toIso8601String();

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'CS 101 Lecture Series',
        'event_category' => Event::CATEGORY_ACADEMIC,
        'contribution_title' => 'Custom Lab Equipment Fund',
        'status' => Event::STATUS_DRAFT,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Maths Block Room 3',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $event = Event::where('name', 'CS 101 Lecture Series')->first();
    expect($event)->not->toBeNull()
        ->and($event->event_category)->toBe(Event::CATEGORY_ACADEMIC)
        ->and($event->contribution_title)->toBe('Custom Lab Equipment Fund');
});

test('Tenant EventController update modifies event_category and payload reflects it', function (): void {
    [$tenant, $user] = eventHost('conference-org');
    $host = eventSubdomainHost('conference-org');

    $startsAt = CarbonImmutable::now()->addDays(4)->setTime(9, 0)->toIso8601String();
    $endsAt = CarbonImmutable::now()->addDays(4)->setTime(17, 0)->toIso8601String();

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Original Community Meetup',
        'event_category' => Event::CATEGORY_GENERAL,
        'status' => Event::STATUS_DRAFT,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'location_type' => 'in_person',
        'address' => 'Impact Hub Accra',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ]);

    $response = $this->actingAs($user)->put("http://{$host}/events/{$event->id}", [
        'name' => 'Upgraded Tech Summit 2026',
        'event_category' => Event::CATEGORY_CONFERENCE,
        'status' => Event::STATUS_DRAFT,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Kempinski Hotel Gold Coast City',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $updated = $event->fresh();
    expect($updated->event_category)->toBe(Event::CATEGORY_CONFERENCE)
        ->and($updated->name)->toBe('Upgraded Tech Summit 2026')
        ->and($updated->isConference())->toBeTrue();
});

test('PublicEventController toPublicPayload provides event_category and contextual lexicon', function (): void {
    [$tenant] = eventHost('gh-memorials');
    $host = eventSubdomainHost('gh-memorials');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Memorial Service for Dr. K. Asante',
        'event_category' => Event::CATEGORY_MEMORIAL,
        'allow_contributions' => true,
        'contribution_title' => null, // Omitted to test dynamic fallback
        'ticket_price' => 0,
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Public/Events/Show')
        ->where('event.event_category', Event::CATEGORY_MEMORIAL)
        ->where('event.contribution_title', 'Funeral Donations & Support')
        ->where('event.lexicon.category', Event::CATEGORY_MEMORIAL)
        ->where('event.lexicon.contributions_tab_label', 'Tributes & Condolences')
        ->where('event.lexicon.contributions_cta_label', 'Contribute / Leave Tribute')
        ->where('event.lexicon.wall_title', 'Tribute & Condolence Wall')
        ->where('event.lexicon.notes_action_label', 'Order of Service')
    );
});

test('EventSessionController supports archetype-tailored session types and rejects invalid types', function (): void {
    [$tenant, $user] = eventHost('academic-sessions');
    $host = eventSubdomainHost('academic-sessions');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'event_category' => Event::CATEGORY_ACADEMIC,
        'starts_at' => CarbonImmutable::now()->addDays(1)->setTime(8, 0),
        'ends_at' => CarbonImmutable::now()->addDays(1)->setTime(18, 0),
    ]);

    $sessionStarts = CarbonImmutable::now()->addDays(1)->setTime(9, 0)->toIso8601String();
    $sessionEnds = CarbonImmutable::now()->addDays(1)->setTime(10, 30)->toIso8601String();

    // 1. Valid academic session type (tutorial)
    $resTutorial = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sessions", [
        'title' => 'Calculus Tutorial Group B',
        'starts_at' => $sessionStarts,
        'ends_at' => $sessionEnds,
        'type' => EventSession::TYPE_TUTORIAL,
    ], ['HTTP_HOST' => $host]);

    $resTutorial->assertOk();
    expect(EventSession::where('title', 'Calculus Tutorial Group B')->first()?->type)->toBe(EventSession::TYPE_TUTORIAL);

    // 2. Valid faith session type (sunday_school)
    $resSundaySchool = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sessions", [
        'title' => 'Children Church / Sunday School',
        'starts_at' => $sessionStarts,
        'ends_at' => $sessionEnds,
        'type' => EventSession::TYPE_SUNDAY_SCHOOL,
    ], ['HTTP_HOST' => $host]);

    $resSundaySchool->assertOk();
    expect(EventSession::where('title', 'Children Church / Sunday School')->first()?->type)->toBe(EventSession::TYPE_SUNDAY_SCHOOL);

    // 3. Valid memorial session type (burial_service)
    $resBurial = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sessions", [
        'title' => 'Burial Service & Committal',
        'starts_at' => $sessionStarts,
        'ends_at' => $sessionEnds,
        'type' => EventSession::TYPE_BURIAL_SERVICE,
    ], ['HTTP_HOST' => $host]);

    $resBurial->assertOk();
    expect(EventSession::where('title', 'Burial Service & Committal')->first()?->type)->toBe(EventSession::TYPE_BURIAL_SERVICE);

    // 4. Invalid session type rejected
    $resInvalid = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/sessions", [
        'title' => 'Invalid Type Session',
        'starts_at' => $sessionStarts,
        'ends_at' => $sessionEnds,
        'type' => 'completely_unrecognized_session_type',
    ], ['HTTP_HOST' => $host]);

    $resInvalid->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

test('Tenant EventController update automatically updates default contribution_title when category changes', function (): void {
    [$tenant, $user] = eventHost('category-switch');
    $host = eventSubdomainHost('category-switch');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Faith Fellowship',
        'event_category' => Event::CATEGORY_FAITH,
        'contribution_title' => 'Tithes & Offerings', // Default for faith
        'status' => Event::STATUS_DRAFT,
        'ticket_price' => 0,
        'currency' => 'GHS',
        'starts_at' => CarbonImmutable::now()->addDays(2)->setTime(10, 0),
        'ends_at' => CarbonImmutable::now()->addDays(2)->setTime(12, 0),
    ]);

    // Update to Memorial without specifying custom contribution_title
    $response = $this->actingAs($user)->put("http://{$host}/events/{$event->id}", [
        'name' => 'Memorial Fellowship',
        'event_category' => Event::CATEGORY_MEMORIAL,
        'status' => Event::STATUS_DRAFT,
        'starts_at' => CarbonImmutable::now()->addDays(2)->setTime(10, 0)->toIso8601String(),
        'ends_at' => CarbonImmutable::now()->addDays(2)->setTime(12, 0)->toIso8601String(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Accra Memorial Hall',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $fresh = $event->fresh();
    expect($fresh->event_category)->toBe(Event::CATEGORY_MEMORIAL)
        ->and($fresh->contribution_title)->toBe('Funeral Donations & Support');
});

test('Tenant EventController rejects null event_category', function (): void {
    [$tenant, $user] = eventHost('null-cat');
    $host = eventSubdomainHost('null-cat');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Standard Event',
        'event_category' => Event::CATEGORY_GENERAL,
        'status' => Event::STATUS_DRAFT,
        'ticket_price' => 0,
        'currency' => 'GHS',
        'starts_at' => CarbonImmutable::now()->addDays(2)->setTime(10, 0),
        'ends_at' => CarbonImmutable::now()->addDays(2)->setTime(12, 0),
    ]);

    $response = $this->actingAs($user)->put("http://{$host}/events/{$event->id}", [
        'name' => 'Standard Event',
        'event_category' => null,
        'status' => Event::STATUS_DRAFT,
        'starts_at' => CarbonImmutable::now()->addDays(2)->setTime(10, 0)->toIso8601String(),
        'ends_at' => CarbonImmutable::now()->addDays(2)->setTime(12, 0)->toIso8601String(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Accra Central',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors(['event_category']);
});

test('Tenant EventController workspace payload exposes contribution attributes and lexicon', function (): void {
    [$tenant, $user] = eventHost('workspace-payload');
    $host = eventSubdomainHost('workspace-payload');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Academic Lecture',
        'event_category' => Event::CATEGORY_ACADEMIC,
        'allow_contributions' => true,
        'contribution_title' => 'Department & Class Fund',
        'contribution_min_amount_pesewas' => 500,
        'show_tribute_wall' => true,
        'show_contributor_amounts' => false,
        'status' => Event::STATUS_DRAFT,
        'ticket_price' => 0,
        'currency' => 'GHS',
        'starts_at' => CarbonImmutable::now()->addDays(2)->setTime(10, 0),
        'ends_at' => CarbonImmutable::now()->addDays(2)->setTime(12, 0),
    ]);

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tenant/Events/Show')
        ->where('event.event_category', Event::CATEGORY_ACADEMIC)
        ->where('event.allow_contributions', true)
        ->where('event.contribution_title', 'Department & Class Fund')
        ->where('event.contribution_min_amount_pesewas', 500)
        ->where('event.show_tribute_wall', true)
        ->where('event.show_contributor_amounts', false)
        ->where('event.lexicon.category', Event::CATEGORY_ACADEMIC)
        ->where('event.lexicon.wall_title', 'Student & Alumni Message Board')
    );
});

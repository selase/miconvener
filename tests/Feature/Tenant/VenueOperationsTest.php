<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Event;
use App\Models\EventOperationPillar;
use App\Models\EventOperationTask;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueFacilityMessage;
use App\Models\VenueInspectionLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    // Venue Host Tenant A (e.g. University of Ghana Medical Centre - UGMC)
    $this->hostTenantA = Tenant::factory()->create(['slug' => 'ugmc-centre', 'isolation_mode' => 'shared']);
    $this->hostUserA = User::factory()->create(['tenant_id' => $this->hostTenantA->id]);
    setPermissionsTeamId($this->hostTenantA->id);
    $this->hostUserA->assignRole('Org Admin');
    $this->hostTenantA->users()->attach($this->hostUserA->id);

    $this->shopA = Shop::create([
        'tenant_id' => $this->hostTenantA->id,
        'name' => 'UGMC Medical Training & Simulation Centre',
        'slug' => 'ugmc-training-centre',
        'email' => 'events@ugmc.ug.edu.gh',
        'phone' => '+233 30 255 1100',
        'address' => 'Legon Bypass, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
        'verified_at' => now(),
        'is_active' => true,
    ]);

    $this->listingA = StoreListing::create([
        'shop_id' => $this->shopA->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Auditorium A (Main Medical Hall)',
        'slug' => 'auditorium-a-'.uniqid(),
        'rental_price_pesewas' => 2500000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Venue Host Tenant B (e.g. Kempinski Hotel)
    $this->hostTenantB = Tenant::factory()->create(['slug' => 'kempinski-accra', 'isolation_mode' => 'shared']);
    $this->hostUserB = User::factory()->create(['tenant_id' => $this->hostTenantB->id]);
    setPermissionsTeamId($this->hostTenantB->id);
    $this->hostUserB->assignRole('Org Admin');
    $this->hostTenantB->users()->attach($this->hostUserB->id);

    $this->shopB = Shop::create([
        'tenant_id' => $this->hostTenantB->id,
        'name' => 'Kempinski Gold Coast City',
        'slug' => 'kempinski-gold-coast',
        'email' => 'events@kempinski.com',
        'phone' => '+233 30 274 0000',
        'address' => 'Gamel Abdul Nasser Ave, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => Shop::VERIFICATION_VERIFIED,
        'is_active' => true,
    ]);

    $this->listingB = StoreListing::create([
        'shop_id' => $this->shopB->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Grand Ballroom',
        'slug' => 'grand-ballroom-'.uniqid(),
        'rental_price_pesewas' => 5000000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    // Outside Event Planner Tenant (e.g. Ghana Medical Association)
    $this->plannerTenant = Tenant::factory()->create(['slug' => 'ghana-med-assoc', 'isolation_mode' => 'shared']);
    $this->plannerUser = User::factory()->create(['tenant_id' => $this->plannerTenant->id]);
    setPermissionsTeamId($this->plannerTenant->id);
    $this->plannerUser->assignRole('Org Admin');
    $this->plannerTenant->users()->attach($this->plannerUser->id);

    // Book Auditorium A at UGMC
    $this->bookingA = VenueBooking::create([
        'tenant_id' => $this->hostTenantA->id,
        'store_listing_id' => $this->listingA->id,
        'shop_id' => $this->shopA->id,
        'planner_tenant_id' => $this->plannerTenant->id,
        'user_id' => $this->plannerUser->id,
        'booking_reference' => 'UGMC-BK-2026-001',
        'planner_name' => 'Dr. Kwame Addo',
        'planner_email' => 'kwame@gma.org.gh',
        'planner_phone' => '+233 20 555 7777',
        'planner_company' => 'Ghana Medical Association',
        'event_type' => 'Annual Medical Congress',
        'guest_count' => 350,
        'layout_style' => 'theater',
        'special_requests' => 'Requires standby generator and 3 lapel mics.',
        'starts_at' => Carbon::tomorrow()->setTime(8, 0),
        'ends_at' => Carbon::tomorrow()->setTime(18, 0),
        'rental_amount_pesewas' => 2500000,
        'total_amount_pesewas' => 2500000,
        'deposit_required_pesewas' => 1250000,
        'amount_paid_pesewas' => 2500000,
        'status' => VenueBooking::STATUS_CONFIRMED,
        'payment_status' => VenueBooking::PAYMENT_FULLY_PAID,
    ]);

    // Create corresponding Event in Planner's workspace linked to this booking
    $this->plannerEvent = Event::factory()->published()->create([
        'tenant_id' => $this->plannerTenant->id,
        'name' => 'National Health & Surgery Summit 2026',
        'slug' => 'surgery-summit-2026',
        'store_listing_id' => $this->listingA->id,
        'venue_booking_id' => $this->bookingA->id,
        'starts_at' => Carbon::tomorrow()->setTime(8, 0),
        'ends_at' => Carbon::tomorrow()->setTime(18, 0),
    ]);
});

test('unauthorized user without manage venue permission is forbidden from venue operations', function (): void {
    $guestUser = User::factory()->create(['tenant_id' => $this->hostTenantA->id]);
    $this->hostTenantA->users()->attach($guestUser->id);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "{$this->hostTenantA->slug}.{$baseDomain}";

    $this->actingAs($guestUser)
        ->get("http://{$host}/venue/operations", ['HTTP_HOST' => $host])
        ->assertForbidden();
});

test('venue host can access operations hub with KPI metrics and hosted events', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "{$this->hostTenantA->slug}.{$baseDomain}";

    $this->actingAs($this->hostUserA)
        ->get("http://{$host}/venue/operations", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Venue/Operations/Index')
            ->where('shop.name', 'UGMC Medical Training & Simulation Centre')
            ->has('stats.upcoming_events_count')
            ->has('hostedEvents', 1)
            ->where('hostedEvents.0.booking_reference', 'UGMC-BK-2026-001')
            ->where('hostedEvents.0.title', 'National Health & Surgery Summit 2026')
        );
});

test('venue host can view specific event facility workspace with details and tasks', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "{$this->hostTenantA->slug}.{$baseDomain}";

    $this->actingAs($this->hostUserA)
        ->get("http://{$host}/venue/operations/{$this->bookingA->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Venue/Operations/Show')
            ->where('booking.id', $this->bookingA->id)
            ->where('booking.booking_reference', 'UGMC-BK-2026-001')
            ->has('defaultChecklists.check_in')
            ->has('defaultChecklists.check_out')
        );
});

test('venue host can create a facility setup task under Venue & Logistics pillar', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "{$this->hostTenantA->slug}.{$baseDomain}";

    $response = $this->actingAs($this->hostUserA)
        ->postJson("http://{$host}/venue/operations/{$this->bookingA->id}/tasks", [
            'title' => 'Chillers on 2 hours prior to start',
            'description' => 'Pre-cool Auditorium A to 20C by 06:00',
            'priority' => 'high',
            'due_date' => Carbon::tomorrow()->format('Y-m-d'),
        ], ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertJson(['success' => true]);

    $task = EventOperationTask::withoutGlobalScopes()->where('title', 'Chillers on 2 hours prior to start')->first();
    expect($task)->not->toBeNull()
        ->and($task->is_venue_task)->toBeTrue()
        ->and($task->venue_shop_id)->toBe($this->shopA->id)
        ->and($task->status)->toBe('not_started');
});

test('venue host can update task status and toggle completed_at timestamp', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "{$this->hostTenantA->slug}.{$baseDomain}";

    EventOperationPillar::seedDefaultsForEvent($this->plannerEvent);
    $pillar = EventOperationPillar::withoutGlobalScopes()->where('event_id', $this->plannerEvent->id)->where('slug', 'venue-logistics')->first();

    $task = EventOperationTask::withoutGlobalScopes()->create([
        'tenant_id' => $this->plannerTenant->id,
        'event_id' => $this->plannerEvent->id,
        'pillar_id' => $pillar->id,
        'title' => 'Mic check on podium',
        'priority' => 'urgent',
        'status' => 'not_started',
        'is_venue_task' => true,
        'venue_shop_id' => $this->shopA->id,
    ]);

    // Mark as in_progress
    $this->actingAs($this->hostUserA)
        ->patchJson("http://{$host}/venue/operations/{$this->bookingA->id}/tasks/{$task->id}", [
            'status' => 'in_progress',
        ], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['success' => true, 'task' => ['status' => 'in_progress']]);

    expect($task->fresh()->status)->toBe('in_progress');

    // Mark as done
    $this->actingAs($this->hostUserA)
        ->patchJson("http://{$host}/venue/operations/{$this->bookingA->id}/tasks/{$task->id}", [
            'status' => 'done',
        ], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($task->fresh()->status)->toBe('done')
        ->and($task->fresh()->completed_at)->not->toBeNull();
});

test('venue host can post a coordination message to the planner', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "{$this->hostTenantA->slug}.{$baseDomain}";

    $this->actingAs($this->hostUserA)
        ->postJson("http://{$host}/venue/operations/{$this->bookingA->id}/messages", [
            'message' => 'Standby generator test scheduled for 06:30. Loading dock open from 05:00.',
        ], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['success' => true]);

    $message = VenueFacilityMessage::where('venue_booking_id', $this->bookingA->id)->first();
    expect($message)->not->toBeNull()
        ->and($message->sender_type)->toBe(VenueFacilityMessage::SENDER_HOST)
        ->and($message->message)->toBe('Standby generator test scheduled for 06:30. Loading dock open from 05:00.');
});

test('venue host can record and sign pre-event check-in walkthrough inspection', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "{$this->hostTenantA->slug}.{$baseDomain}";

    $checklist = [
        ['item' => 'Power & Generator Backup', 'status' => 'good', 'notes' => 'Tested on load'],
        ['item' => 'HVAC / Air Conditioning', 'status' => 'good', 'notes' => 'Chillers holding 20C'],
        ['item' => 'Audio / Visual Systems', 'status' => 'good', 'notes' => 'All 3 lapel mics paired'],
    ];

    $this->actingAs($this->hostUserA)
        ->postJson("http://{$host}/venue/operations/{$this->bookingA->id}/inspections", [
            'type' => 'check_in',
            'inspector_name' => 'Eng. Mensah (UGMC Facilities)',
            'checklist' => $checklist,
            'general_notes' => 'Room handed over clean and in perfect readiness.',
            'sign_now' => true,
        ], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['success' => true]);

    $log = VenueInspectionLog::where('venue_booking_id', $this->bookingA->id)
        ->where('type', 'check_in')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('passed')
        ->and($log->signed_at)->not->toBeNull()
        ->and($log->signed_by_name)->toBe('Eng. Mensah (UGMC Facilities)');
});

test('cross-tenant isolation prevents host B from accessing or mutating host A booking', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $hostB = "{$this->hostTenantB->slug}.{$baseDomain}";

    // Host B attempts to view Host A booking -> 404
    $this->actingAs($this->hostUserB)
        ->get("http://{$hostB}/venue/operations/{$this->bookingA->id}", ['HTTP_HOST' => $hostB])
        ->assertNotFound();

    // Host B attempts to post message to Host A booking -> 404
    $this->actingAs($this->hostUserB)
        ->postJson("http://{$hostB}/venue/operations/{$this->bookingA->id}/messages", [
            'message' => 'Unauthorized intruder message',
        ], ['HTTP_HOST' => $hostB])
        ->assertNotFound();
});

test('event organizer can view facility collaboration data for their event', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $plannerHost = "{$this->plannerTenant->slug}.{$baseDomain}";

    $this->actingAs($this->plannerUser)
        ->getJson("http://{$plannerHost}/events/{$this->plannerEvent->id}/venue-collaboration", ['HTTP_HOST' => $plannerHost])
        ->assertOk()
        ->assertJson([
            'has_venue' => true,
            'booking' => [
                'booking_reference' => 'UGMC-BK-2026-001',
                'venue_name' => 'UGMC Medical Training & Simulation Centre',
            ],
        ]);
});

test('event organizer can send message to venue host in facility thread', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $plannerHost = "{$this->plannerTenant->slug}.{$baseDomain}";

    $this->actingAs($this->plannerUser)
        ->postJson("http://{$plannerHost}/events/{$this->plannerEvent->id}/venue-collaboration/messages", [
            'message' => 'Please confirm we will have 4 stage chairs for our opening panel.',
        ], ['HTTP_HOST' => $plannerHost])
        ->assertOk()
        ->assertJson(['success' => true]);

    $message = VenueFacilityMessage::where('event_id', $this->plannerEvent->id)
        ->where('sender_type', VenueFacilityMessage::SENDER_PLANNER)
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->message)->toBe('Please confirm we will have 4 stage chairs for our opening panel.');
});

test('event organizer can sign walkthrough inspection report', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $plannerHost = "{$this->plannerTenant->slug}.{$baseDomain}";

    // First host creates unsigned check_in log
    VenueInspectionLog::create([
        'shop_id' => $this->shopA->id,
        'venue_booking_id' => $this->bookingA->id,
        'event_id' => $this->plannerEvent->id,
        'type' => 'check_in',
        'inspector_name' => 'Host Lead',
        'inspector_role' => 'host',
        'status' => 'passed',
        'checklist' => VenueInspectionLog::defaultChecklist('check_in'),
    ]);

    $this->actingAs($this->plannerUser)
        ->postJson("http://{$plannerHost}/events/{$this->plannerEvent->id}/venue-collaboration/inspections", [
            'type' => 'check_in',
            'inspector_name' => 'Dr. Kwame Addo (GMA President)',
            'sign_now' => true,
        ], ['HTTP_HOST' => $plannerHost])
        ->assertOk()
        ->assertJson(['success' => true]);

    $log = VenueInspectionLog::where('event_id', $this->plannerEvent->id)->where('type', 'check_in')->first();
    expect($log->signed_at)->not->toBeNull()
        ->and($log->signed_by_name)->toBe('Dr. Kwame Addo (GMA President)');
});

test('events with no linked venue return has_venue false gracefully', function (): void {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $plannerHost = "{$this->plannerTenant->slug}.{$baseDomain}";

    $independentEvent = Event::factory()->published()->create([
        'tenant_id' => $this->plannerTenant->id,
        'store_listing_id' => null,
        'venue_booking_id' => null,
    ]);

    $this->actingAs($this->plannerUser)
        ->getJson("http://{$plannerHost}/events/{$independentEvent->id}/venue-collaboration", ['HTTP_HOST' => $plannerHost])
        ->assertOk()
        ->assertJson([
            'has_venue' => false,
        ]);
});

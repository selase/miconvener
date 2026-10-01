<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    // Organizer Tenant
    $this->organizerTenant = Tenant::factory()->create(['slug' => 'tech-events-gh', 'isolation_mode' => 'shared']);
    $this->organizer = User::factory()->create(['tenant_id' => $this->organizerTenant->id]);
    setPermissionsTeamId($this->organizerTenant->id);
    $this->organizer->assignRole('Org Admin');
    $this->organizerTenant->users()->attach($this->organizer->id);

    // Host Tenant & Marketplace Venue
    $this->hostTenant = Tenant::factory()->create(['slug' => 'marriott-accra', 'isolation_mode' => 'shared']);
    $this->venueShop = Shop::create([
        'tenant_id' => $this->hostTenant->id,
        'name' => 'Accra Marriott Hotel',
        'slug' => 'accra-marriott-hotel-'.uniqid(),
        'email' => 'events@marriottaccra.com',
        'phone' => '+233 30 273 8000',
        'address' => 'Airport City, Liberation Road',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => 'verified',
        'is_active' => true,
    ]);

    $this->venueListing = StoreListing::create([
        'shop_id' => $this->venueShop->id,
        'listing_kind' => StoreListing::KIND_VENUE,
        'title' => 'Kwame Nkrumah Grand Ballroom',
        'slug' => 'kwame-nkrumah-grand-ballroom-'.uniqid(),
        'rental_price_pesewas' => 5000000,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
        'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'security_deposit_pesewas' => 500000,
        'floor_area_sqm' => 650,
        'ceiling_height_meters' => 6.5,
        'capacity_breakdown' => ['banquet' => 450, 'theater' => 700],
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);
});

test('organizer can link marketplace venue space when creating event with auto-synced address and capacity', function (): void {
    $startsAt = Carbon::tomorrow()->setTime(9, 0)->format('Y-m-d H:i');
    $endsAt = Carbon::tomorrow()->setTime(18, 0)->format('Y-m-d H:i');

    $host = "{$this->organizerTenant->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($this->organizer)
        ->post("http://{$host}/events", [
            'name' => 'West Africa Cloud Summit 2026',
            'description' => 'Annual regional summit for cloud architects.',
            'location_type' => Event::LOCATION_IN_PERSON,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => 'Africa/Accra',
            'ticket_price' => 0,
            'currency' => 'GHS',
            'status' => Event::STATUS_PUBLISHED,
            'store_listing_id' => $this->venueListing->id,
            // Address and capacity left blank to test auto-population
        ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();

    $event = Event::where('name', 'West Africa Cloud Summit 2026')->first();
    expect($event)->not->toBeNull()
        ->and($event->store_listing_id)->toBe($this->venueListing->id)
        ->and($event->isMarketplaceVenue())->toBeTrue()
        ->and($event->address)->toBe('Airport City, Liberation Road, Accra')
        ->and($event->capacity)->toBe(450); // Auto-populated from banquet capacity
});

test('organizer can update existing event to link a booked venue space', function (): void {
    $event = Event::factory()->create([
        'tenant_id' => $this->organizerTenant->id,
        'created_by' => $this->organizer->id,
        'name' => 'DevOps Meetup Accra',
        'location_type' => Event::LOCATION_IN_PERSON,
        'address' => 'Temporary Co-working Space',
        'capacity' => 50,
    ]);

    $host = "{$this->organizerTenant->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($this->organizer)
        ->put("http://{$host}/events/{$event->id}", [
            'name' => 'DevOps Meetup Accra (Grand Edition)',
            'description' => $event->description ?? 'Expanded edition at Accra Marriott.',
            'location_type' => Event::LOCATION_IN_PERSON,
            'starts_at' => $event->starts_at->format('Y-m-d H:i'),
            'ends_at' => $event->ends_at->format('Y-m-d H:i'),
            'timezone' => 'Africa/Accra',
            'ticket_price' => 0,
            'currency' => 'GHS',
            'status' => Event::STATUS_PUBLISHED,
            'store_listing_id' => $this->venueListing->id,
            'address' => 'Airport City, Liberation Road, Accra',
            'capacity' => 450,
        ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();

    $event->refresh();
    expect($event->store_listing_id)->toBe($this->venueListing->id)
        ->and($event->isMarketplaceVenue())->toBeTrue();
});

test('public event page renders verified venue partner details when linked to marketplace listing', function (): void {
    $event = Event::factory()->create([
        'tenant_id' => $this->organizerTenant->id,
        'created_by' => $this->organizer->id,
        'name' => 'Pan-African Fintech Forum',
        'slug' => 'pan-african-fintech-forum',
        'status' => Event::STATUS_PUBLISHED,
        'location_type' => Event::LOCATION_IN_PERSON,
        'store_listing_id' => $this->venueListing->id,
    ]);

    $host = "{$this->organizerTenant->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $response = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/Show')
            ->where('event.is_marketplace_venue', true)
            ->where('event.venue_listing.id', $this->venueListing->id)
            ->where('event.venue_listing.title', 'Kwame Nkrumah Grand Ballroom')
            ->where('event.venue_listing.shop.name', 'Accra Marriott Hotel')
            ->where('event.venue_listing.shop.verification_status', 'verified')
            ->where('event.venue_listing.floor_area_sqm', fn ($val) => (float) $val === 650.0)
        );
});

test('public event page returns null venue_listing for unlinked standard event', function (): void {
    $event = Event::factory()->create([
        'tenant_id' => $this->organizerTenant->id,
        'created_by' => $this->organizer->id,
        'name' => 'Online Webinar',
        'slug' => 'online-webinar',
        'status' => Event::STATUS_PUBLISHED,
        'location_type' => Event::LOCATION_VIRTUAL,
        'store_listing_id' => null,
    ]);

    $host = "{$this->organizerTenant->slug}.".mb_ltrim((string) config('session.domain'), '.');

    $response = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host]);

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/Show')
            ->where('event.is_marketplace_venue', false)
            ->where('event.venue_listing', null)
        );
});

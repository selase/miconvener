<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreListing;
use Database\Seeders\MarketplaceDemoSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StoreAmenitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionsSeeder::class);
    $this->seed(StoreAmenitySeeder::class);
    $this->seed(MarketplaceDemoSeeder::class);
});

test('marketplace demo seeder creates UGMC shop with logo and cover photo', function (): void {
    $shop = Shop::where('slug', 'ugmc')->first();

    expect($shop)->not->toBeNull()
        ->and($shop->name)->toBe('University of Ghana Medical Centre (UGMC)')
        ->and($shop->city)->toBe('Accra')
        ->and($shop->region)->toBe('Greater Accra')
        ->and($shop->email)->toBe('mtsc@ugmc.ug.edu.gh')
        ->and($shop->logo_path)->toBe('https://ugmedicalcentre.org/front/images/ugmclogo.jpg')
        ->and($shop->cover_image_path)->toBe('https://ugmedicalcentre.org/front/images/buildings/sim_tuition@2x-min.jpg')
        ->and($shop->is_active)->toBeTrue();
});

test('marketplace demo seeder creates all 6 UGMC MTSC venue spaces with media and amenities', function (): void {
    $shop = Shop::where('slug', 'ugmc')->firstOrFail();
    $spaces = StoreListing::where('shop_id', $shop->id)
        ->with(['media', 'primaryMedia', 'amenities.amenity'])
        ->get();

    expect($spaces)->toHaveCount(6);

    $expectedSlugs = [
        'mtsc-main-auditorium',
        'executive-seminar-room-a-60-seater',
        'executive-seminar-room-b-40-seater',
        'the-mtsc-atrium-and-exhibition-foyer',
        'debriefing-and-focus-suite',
        'computer-based-testing-and-assessment-suite',
    ];

    expect($spaces->pluck('slug')->all())->toEqualCanonicalizing($expectedSlugs);

    foreach ($spaces as $space) {
        expect($space->is_bookable)->toBeFalse()
            ->and($space->isBookable())->toBeFalse()
            ->and($space->isPriceOnRequest())->toBeTrue()
            ->and($space->status)->toBe(StoreListing::STATUS_PUBLISHED)
            ->and($space->rental_price_pesewas)->toBeGreaterThan(0)
            ->and($space->primaryMedia)->not->toBeNull()
            ->and($space->media->count())->toBeGreaterThanOrEqual(2)
            ->and($space->amenities->count())->toBeGreaterThan(0);
    }
});

test('UGMC auditorium space is discoverable via public marketplace show route', function (): void {
    $response = $this->get('/marketplace/venues/mtsc-main-auditorium');

    $response->assertOk();
    $response->assertSee('MTSC Main Auditorium');
    $response->assertSee('University of Ghana Medical Centre');
});

test('booking attempt on UGMC space is rejected with 422 because it is unbookable', function (): void {
    $payload = [
        'starts_at' => now()->addDays(14)->setTime(8, 0)->toDateTimeString(),
        'ends_at' => now()->addDays(14)->setTime(17, 0)->toDateTimeString(),
        'guest_count' => 120,
        'layout_style' => 'theater',
        'event_type' => 'Medical Summit',
        'planner_name' => 'Dr. Mensah',
        'planner_email' => 'mensah@health.test',
        'planner_phone' => '+233 24 111 2233',
        'agreed_to_terms' => true,
    ];

    // Web form submission redirects back with validation errors
    $webResponse = $this->post('/marketplace/venues/mtsc-main-auditorium/book', $payload);
    $webResponse->assertSessionHasErrors(['starts_at']);

    // JSON API request returns HTTP 422 Unprocessable Entity
    $jsonResponse = $this->postJson('/marketplace/venues/mtsc-main-auditorium/book', $payload);
    $jsonResponse->assertStatus(422)
        ->assertJsonValidationErrors(['starts_at']);
});

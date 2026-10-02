<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\StoreAmenity;
use App\Models\StoreListing;
use App\Models\StoreListingAmenity;
use App\Models\StoreListingMedia;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class MarketplaceDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure amenities exist
        $amenityMap = StoreAmenity::all()->keyBy('slug');
        if ($amenityMap->isEmpty()) {
            $this->call(StoreAmenitySeeder::class);
            $amenityMap = StoreAmenity::all()->keyBy('slug');
        }

        // 2. Setup Venue Merchants
        $merchants = [
            [
                'tenant_name' => 'Labadi Beach Hotel',
                'tenant_slug' => 'labadi-beach-hotel',
                'shop_name' => 'Labadi Beach Hotel',
                'shop_slug' => 'labadi-beach-hotel',
                'description' => "Ghana's premier 5-star beachfront resort, set in tropical landscaped gardens overlooking the Gulf of Guinea. Offering world-class conferencing, grand banqueting, and intimate meeting suites with uninterrupted power and dedicated event concierges.",
                'city' => 'Accra',
                'region' => 'Greater Accra',
                'address' => 'No 1 La Bypass, Trade Fair Area, Accra',
                'latitude' => 5.5606,
                'longitude' => -0.1557,
                'phone' => '+233 30 277 2501',
                'email' => 'events@labadibeachhotelgh.com',
                'verification_status' => Shop::VERIFICATION_VERIFIED,
                'verified_at' => now()->subMonths(3),
                'spaces' => [
                    [
                        'title' => 'Omanye Plenary Hall',
                        'slug' => 'omanye-plenary-hall',
                        'description' => "The iconic Omanye Hall is West Africa's preferred plenary and summit auditorium. Pillarless architecture with acoustic paneling, high ceilings, automated stage rigging, and direct drive-in access for exhibition vehicles.",
                        'rental_price_pesewas' => 2500000, // GHS 25,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 800, 'banquet' => 450, 'cocktail' => 1000, 'classroom' => 350],
                        'floor_area' => 750.00,
                        'ceiling_height' => 6.50,
                        'image' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=1200&q=80',
                        'included_amenities' => ['central_ac', 'generator_standby', 'pa_sound_system', 'wifi_high_speed', 'parking_on_site', 'vip_green_room', 'executive_restrooms', 'wheelchair_access'],
                        'excluded_amenities' => [
                            ['slug' => 'led_video_wall', 'notes' => 'In-house high-res P3.91 LED Wall available for hire at GHS 5,000 / day'],
                            ['slug' => 'stage_lighting', 'notes' => 'Full dynamic stage lighting package available at GHS 3,500 / day'],
                        ],
                    ],
                    [
                        'title' => 'Palm Court Ocean Suite',
                        'slug' => 'palm-court-ocean-suite',
                        'description' => 'Scenic ballroom opening directly onto beachfront manicured lawns. Ideal for corporate dinners, cocktail soirees, product launches, and medium-scale symposiums with sea views.',
                        'rental_price_pesewas' => 1200000, // GHS 12,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 180, 'banquet' => 120, 'cocktail' => 250, 'classroom' => 90],
                        'floor_area' => 220.00,
                        'ceiling_height' => 4.20,
                        'image' => 'https://images.unsplash.com/photo-1519167758481-83f550bb49b3?auto=format&fit=crop&w=1200&q=80',
                        'included_amenities' => ['central_ac', 'generator_standby', 'wifi_high_speed', 'parking_on_site', 'executive_restrooms'],
                        'excluded_amenities' => [
                            ['slug' => 'pa_sound_system', 'notes' => 'Compact wireless microphone & PA package available at GHS 1,500 / day'],
                        ],
                    ],
                    [
                        'title' => 'Executive Ocean Boardroom',
                        'slug' => 'executive-ocean-boardroom',
                        'description' => 'Ultra-private executive boardroom featuring ergonomic leather seating, dual 4K displays for hybrid video conferencing, and dedicated butler refreshment station.',
                        'rental_price_pesewas' => 0,
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 35, 'banquet' => 20, 'classroom' => 25],
                        'floor_area' => 65.00,
                        'ceiling_height' => 3.50,
                        'image' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=1200&q=80',
                        'included_amenities' => ['central_ac', 'wifi_high_speed', 'executive_restrooms', 'generator_standby'],
                        'excluded_amenities' => [],
                    ],
                ],
            ],
            [
                'tenant_name' => 'Kempinski Hotel Gold Coast City',
                'tenant_slug' => 'kempinski-accra',
                'shop_name' => 'Kempinski Hotel Gold Coast City',
                'shop_slug' => 'kempinski-accra',
                'description' => "Centrally situated in the ministerial enclave of Accra, Kempinski Gold Coast City offers European luxury combined with Ghanaian warmth. Boasting the capital's largest ballroom and VIP reception salons.",
                'city' => 'Accra',
                'region' => 'Greater Accra',
                'address' => 'Gamal Abdul Nasser Avenue, Ministries, Accra',
                'latitude' => 5.5489,
                'longitude' => -0.1989,
                'phone' => '+233 24 243 6000',
                'email' => 'meetings.accra@kempinski.com',
                'verification_status' => Shop::VERIFICATION_VERIFIED,
                'verified_at' => now()->subMonths(5),
                'spaces' => [
                    [
                        'title' => 'Grand Pavilion Ballroom',
                        'slug' => 'grand-pavilion-ballroom',
                        'description' => "Accra's most prestigious grand ballroom. 1,100 square meters of pillar-free luxury with crystal chandeliers, customizable acoustic dividing walls, state-of-the-art motorized projection systems, and dedicated banquet staging kitchens.",
                        'rental_price_pesewas' => 4500000, // GHS 45,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 1200, 'banquet' => 750, 'cocktail' => 1500, 'classroom' => 500],
                        'floor_area' => 1100.00,
                        'ceiling_height' => 7.20,
                        'image' => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?auto=format&fit=crop&w=1200&q=80',
                        'included_amenities' => ['central_ac', 'generator_standby', 'pa_sound_system', 'wifi_high_speed', 'parking_on_site', 'vip_green_room', 'executive_restrooms', 'wheelchair_access', 'loading_dock'],
                        'excluded_amenities' => [
                            ['slug' => 'led_video_wall', 'notes' => 'Dual P2.6 ultra-HD indoor video walls available at GHS 8,000 / day'],
                        ],
                    ],
                    [
                        'title' => 'Adabraka Breakout Salon',
                        'slug' => 'adabraka-breakout-salon',
                        'description' => 'Natural daylight breakout salon featuring floor-to-ceiling soundproof glass, integrated ceiling audio, and motorized drop-down screens for workshops and parallel summit tracks.',
                        'rental_price_pesewas' => 850000, // GHS 8,500
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 90, 'banquet' => 60, 'cocktail' => 110, 'classroom' => 45],
                        'floor_area' => 115.00,
                        'ceiling_height' => 4.00,
                        'image' => 'https://images.unsplash.com/photo-1431540015161-0bf868a2d407?auto=format&fit=crop&w=1200&q=80',
                        'included_amenities' => ['central_ac', 'wifi_high_speed', 'executive_restrooms', 'generator_standby', 'pa_sound_system'],
                        'excluded_amenities' => [],
                    ],
                ],
            ],
            [
                'tenant_name' => 'Ridge Royal Hotel',
                'tenant_slug' => 'ridge-royal-cape-coast',
                'shop_name' => 'Ridge Royal Hotel',
                'shop_slug' => 'ridge-royal-cape-coast',
                'description' => 'Overlooking historic Cape Coast, Ridge Royal Hotel provides modern conference facilities and hilltop serenity for regional conferences, executive retreats, and national association AGMs in Central Region.',
                'city' => 'Cape Coast',
                'region' => 'Central',
                'address' => 'Second Ridge, Cape Coast',
                'latitude' => 5.1189,
                'longitude' => -1.2589,
                'phone' => '+233 33 211 1000',
                'email' => 'reservations@ridgeroyalhotel.com',
                'verification_status' => Shop::VERIFICATION_VERIFIED,
                'verified_at' => now()->subMonths(2),
                'spaces' => [
                    [
                        'title' => 'Royal Heritage Plenary Hall',
                        'slug' => 'royal-heritage-plenary-hall',
                        'description' => 'The premier conference facility in the Central Region. Spacious air-conditioned auditorium with acoustic treatment, dual backup power generators, and full AV projection.',
                        'rental_price_pesewas' => 1400000, // GHS 14,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 450, 'banquet' => 280, 'cocktail' => 500, 'classroom' => 200],
                        'floor_area' => 420.00,
                        'ceiling_height' => 5.00,
                        'image' => 'https://images.unsplash.com/photo-1505373877841-8d25f7d46678?auto=format&fit=crop&w=1200&q=80',
                        'included_amenities' => ['central_ac', 'generator_standby', 'pa_sound_system', 'wifi_high_speed', 'parking_on_site', 'executive_restrooms'],
                        'excluded_amenities' => [
                            ['slug' => 'stage_lighting', 'notes' => 'Stage lighting setup available on request at GHS 2,000 / day'],
                        ],
                    ],
                ],
            ],
            [
                'tenant_name' => 'Lancaster Kumasi',
                'tenant_slug' => 'lancaster-kumasi',
                'shop_name' => 'Lancaster Kumasi',
                'shop_slug' => 'lancaster-kumasi',
                'description' => "Ashanti Region's landmark hospitality destination set within 10 acres of lush gardens. The top choice for corporate summits, mining symposiums, and celebratory galas in Kumasi.",
                'city' => 'Kumasi',
                'region' => 'Ashanti',
                'address' => 'NH6, Nhyiaeso, Kumasi',
                'latitude' => 6.6971,
                'longitude' => -1.6322,
                'phone' => '+233 32 208 3700',
                'email' => 'banqueting@lancasterkumasi.com',
                'verification_status' => Shop::VERIFICATION_VERIFIED,
                'verified_at' => now()->subMonths(1),
                'spaces' => [
                    [
                        'title' => 'Manhyia Conference Hall',
                        'slug' => 'manhyia-conference-hall',
                        'description' => 'Spacious column-free conference center accommodating up to 600 delegates. Equipped with heavy-duty backup generators, perimeter security, and ample parking inside the Nhyiaeso estate.',
                        'rental_price_pesewas' => 1800000, // GHS 18,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 600, 'banquet' => 350, 'cocktail' => 700, 'classroom' => 250],
                        'floor_area' => 580.00,
                        'ceiling_height' => 5.50,
                        'image' => 'https://images.unsplash.com/photo-1517457373958-b7bdd4587205?auto=format&fit=crop&w=1200&q=80',
                        'included_amenities' => ['central_ac', 'generator_standby', 'pa_sound_system', 'wifi_high_speed', 'parking_on_site', 'executive_restrooms', 'wheelchair_access'],
                        'excluded_amenities' => [
                            ['slug' => 'led_video_wall', 'notes' => 'LED screen rental available via hotel AV partner at GHS 4,500 / day'],
                        ],
                    ],
                ],
            ],
            [
                'tenant_name' => 'University of Ghana Medical Centre',
                'tenant_slug' => 'ugmc',
                'shop_name' => 'University of Ghana Medical Centre (UGMC)',
                'shop_slug' => 'ugmc',
                'description' => "West Africa's premier quaternary healthcare, academic, and medical training facility. Featuring the world-class Medical Training and Simulation Centre (MTSC) with state-of-the-art auditoriums, smart seminar suites, interactive simulation debriefing theaters, and an expansive glass atrium for symposiums, corporate workshops, and professional conferences.",
                'city' => 'Accra',
                'region' => 'Greater Accra',
                'address' => 'University of Ghana Medical Centre, Legon Bypass, Accra',
                'latitude' => 5.6325,
                'longitude' => -0.1855,
                'phone' => '+233 302 550843',
                'email' => 'mtsc@ugmc.ug.edu.gh',
                'logo_path' => 'https://ugmedicalcentre.org/front/images/ugmclogo.jpg',
                'cover_image_path' => 'https://ugmedicalcentre.org/front/images/buildings/sim_tuition@2x-min.jpg',
                'verification_status' => Shop::VERIFICATION_VERIFIED,
                'verified_at' => now()->subMonths(2),
                'spaces' => [
                    [
                        'title' => 'MTSC Main Auditorium',
                        'slug' => 'mtsc-main-auditorium',
                        'description' => 'Flagship 150-seater plenary auditorium situated in the Medical Training and Simulation Centre (MTSC). Features sloped theatre seating, an expansive stage with speaker lectern, dual high-definition projection, sound reinforcement, acoustic wall paneling, and high-resolution event recording capabilities.',
                        'rental_price_pesewas' => 1500000, // GHS 15,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 150, 'classroom' => 80],
                        'floor_area' => 220.00,
                        'ceiling_height' => 5.20,
                        'image' => 'https://ugmedicalcentre.org/front/images/simulation/tour/3A-50-Seater-Auditorium.jpg',
                        'gallery' => [
                            'https://ugmedicalcentre.org/front/images/simulation/mtsc-rooms/auditorium.jpg',
                            'https://ugmedicalcentre.org/front/images/buildings/sim_tuition@2x-min.jpg',
                            'https://ugmedicalcentre.org/front/images/simulation/tour/11-Staff-coffee-break.jpg',
                        ],
                        'included_amenities' => ['central_ac', 'generator_standby', 'pa_sound_system', 'wireless_mics', 'projector_screens', 'podium', 'stage', 'wifi_high_speed', 'parking_on_site', 'executive_restrooms', 'wheelchair_access', 'security_guards', 'in_house_catering'],
                        'excluded_amenities' => [
                            ['slug' => 'led_video_wall', 'notes' => 'LED screen package available upon request for symposiums'],
                        ],
                    ],
                    [
                        'title' => 'Executive Seminar Room A (60-Seater)',
                        'slug' => 'executive-seminar-room-a-60-seater',
                        'description' => 'Spacious modular seminar and training suite designed for corporate workshops, health symposiums, and professional certification programs. Features flexible table layouts, ergonomic executive seating, interactive smart displays, hybrid videoconferencing, and natural lighting.',
                        'rental_price_pesewas' => 600000, // GHS 6,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['classroom' => 60, 'theater' => 80, 'banquet' => 45],
                        'floor_area' => 110.00,
                        'ceiling_height' => 3.80,
                        'image' => 'https://ugmedicalcentre.org/front/images/simulation/mtsc-rooms/sixty-seater-rooms.jpg',
                        'gallery' => [
                            'https://ugmedicalcentre.org/front/images/simulation/tour/4-Pre-simulation-training-test.jpg',
                            'https://ugmedicalcentre.org/front/images/simulation/tour/12A-Cafeteria-services-at-the-Centre.jpg',
                        ],
                        'included_amenities' => ['central_ac', 'generator_standby', 'wifi_high_speed', 'projector_screens', 'podium', 'parking_on_site', 'executive_restrooms', 'wheelchair_access'],
                        'excluded_amenities' => [
                            ['slug' => 'pa_sound_system', 'notes' => 'Supplemental wireless lapel mic package available on request'],
                        ],
                    ],
                    [
                        'title' => 'Executive Seminar Room B (40-Seater)',
                        'slug' => 'executive-seminar-room-b-40-seater',
                        'description' => 'Comfortable and focused workshop room ideal for interactive seminars, department retreats, and breakout sessions. Equipped with high-speed Wi-Fi, digital presentation display, mobile whiteboards, and climate control.',
                        'rental_price_pesewas' => 400000, // GHS 4,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['classroom' => 40, 'theater' => 50, 'banquet' => 30],
                        'floor_area' => 80.00,
                        'ceiling_height' => 3.80,
                        'image' => 'https://ugmedicalcentre.org/front/images/simulation/mtsc-rooms/forty-seater-rooms.jpg',
                        'gallery' => [
                            'https://ugmedicalcentre.org/front/images/simulation/tour/10B-State-of-the-art-E-library.jpg',
                        ],
                        'included_amenities' => ['central_ac', 'generator_standby', 'wifi_high_speed', 'projector_screens', 'parking_on_site', 'executive_restrooms', 'wheelchair_access'],
                        'excluded_amenities' => [],
                    ],
                    [
                        'title' => 'The MTSC Atrium & Exhibition Foyer',
                        'slug' => 'the-mtsc-atrium-and-exhibition-foyer',
                        'description' => 'An expansive, light-filled multi-story reception and networking atrium featuring polished floors and glass architecture. Perfect for conference registration hubs, welcome cocktail receptions, poster presentations, medical equipment exhibitions, and catering breaks.',
                        'rental_price_pesewas' => 1000000, // GHS 10,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['cocktail' => 250, 'banquet' => 120, 'theater' => 150],
                        'floor_area' => 350.00,
                        'ceiling_height' => 8.50,
                        'image' => 'https://ugmedicalcentre.org/front/images/simulation/tour/1-The-atrium.jpg',
                        'gallery' => [
                            'https://ugmedicalcentre.org/front/images/simulation/mtsc-rooms/atrium.jpg',
                            'https://ugmedicalcentre.org/front/images/buildings/sim_tuition@2x-min.jpg',
                            'https://ugmedicalcentre.org/front/images/simulation/tour/12A-Cafeteria-services-at-the-Centre.jpg',
                        ],
                        'included_amenities' => ['central_ac', 'generator_standby', 'wifi_high_speed', 'parking_on_site', 'wheelchair_access', 'executive_restrooms', 'security_guards'],
                        'excluded_amenities' => [
                            ['slug' => 'cocktail_tables', 'notes' => 'High cocktail tables available from venue rental inventory'],
                        ],
                    ],
                    [
                        'title' => 'Debriefing & Focus Suite',
                        'slug' => 'debriefing-and-focus-suite',
                        'description' => 'High-tech debriefing and deliberation boardroom equipped with one-way observation capabilities, dual camera audio/video playback, and presentation displays. Specially tailored for medical reviews, focus groups, arbitration, and confidential committee sessions.',
                        'rental_price_pesewas' => 300000, // GHS 3,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['theater' => 25, 'classroom' => 20, 'banquet' => 15],
                        'floor_area' => 50.00,
                        'ceiling_height' => 3.50,
                        'image' => 'https://ugmedicalcentre.org/front/images/simulation/mtsc-rooms/debriefing-rooms.jpg',
                        'gallery' => [
                            'https://ugmedicalcentre.org/front/images/simulation/tour/8B-Debriefing-after-Simulation-Training.jpg',
                            'https://ugmedicalcentre.org/front/images/simulation/tour/6-Instructor-monitoring.jpg',
                        ],
                        'included_amenities' => ['central_ac', 'generator_standby', 'wifi_high_speed', 'projector_screens', 'parking_on_site', 'executive_restrooms'],
                        'excluded_amenities' => [],
                    ],
                    [
                        'title' => 'Computer-Based Testing & Assessment Suite',
                        'slug' => 'computer-based-testing-and-assessment-suite',
                        'description' => 'Dedicated digital assessment laboratory equipped with 30 networked workstations, high-speed fiber internet, uninterruptible power supply, and invigilator monitoring station. Ideal for professional board exams, certification testing, and software training workshops.',
                        'rental_price_pesewas' => 500000, // GHS 5,000
                        'pricing_model' => StoreListing::PRICING_MODEL_PER_DAY,
                        'price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST,
                        'is_bookable' => false,
                        'capacity' => ['classroom' => 30],
                        'floor_area' => 75.00,
                        'ceiling_height' => 3.50,
                        'image' => 'https://ugmedicalcentre.org/front/images/simulation/mtsc-rooms/test-rooms.jpg',
                        'gallery' => [
                            'https://ugmedicalcentre.org/front/images/simulation/tour/10B-State-of-the-art-E-library.jpg',
                        ],
                        'included_amenities' => ['central_ac', 'generator_standby', 'wifi_high_speed', 'parking_on_site', 'executive_restrooms', 'security_guards'],
                        'excluded_amenities' => [],
                    ],
                ],
            ],
        ];

        foreach ($merchants as $m) {
            // Find or create tenant
            $tenant = Tenant::firstOrCreate(
                ['slug' => $m['tenant_slug']],
                [
                    'name' => $m['tenant_name'],
                    'isolation_mode' => 'shared',
                    'onboarding_completed_at' => now(),
                ]
            );

            // Create or ensure Venue Host User exists for the merchant tenant
            $userEmail = "venue@{$m['tenant_slug']}.test";
            $hostUser = User::firstOrCreate(
                ['email' => $userEmail],
                [
                    'first_name' => $m['shop_name'],
                    'last_name' => 'Host',
                    'password' => Hash::make('password'),
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'tenant_id' => $tenant->id,
                ]
            );

            if (! $tenant->users()->where('users.id', $hostUser->id)->exists()) {
                $tenant->users()->attach($hostUser->id);
            }

            setPermissionsTeamId($tenant->id);
            $hostUser->syncRoles(['Org Admin']);

            // Create or update Shop profile
            $shop = Shop::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'name' => $m['shop_name'],
                    'slug' => $m['shop_slug'],
                    'description' => $m['description'],
                    'logo_path' => $m['logo_path'] ?? null,
                    'cover_image_path' => $m['cover_image_path'] ?? null,
                    'city' => $m['city'],
                    'region' => $m['region'],
                    'address' => $m['address'],
                    'latitude' => $m['latitude'],
                    'longitude' => $m['longitude'],
                    'phone' => $m['phone'],
                    'email' => $m['email'],
                    'verification_status' => $m['verification_status'],
                    'verified_at' => $m['verified_at'],
                    'is_active' => true,
                ]
            );

            // Create Spaces
            foreach ($m['spaces'] as $idx => $s) {
                $listing = StoreListing::updateOrCreate(
                    ['slug' => $s['slug']],
                    [
                        'shop_id' => $shop->id,
                        'listing_kind' => StoreListing::KIND_VENUE,
                        'title' => $s['title'],
                        'description' => $s['description'],
                        'rental_price_pesewas' => $s['rental_price_pesewas'],
                        'pricing_model' => $s['pricing_model'],
                        'price_visibility' => $s['price_visibility'],
                        'is_bookable' => (bool) $s['is_bookable'],
                        'capacity_breakdown' => $s['capacity'],
                        'floor_area_sqm' => $s['floor_area'],
                        'ceiling_height_meters' => $s['ceiling_height'],
                        'status' => StoreListing::STATUS_PUBLISHED,
                        'sort_order' => $idx + 1,
                    ]
                );

                // Add Primary Photo Media
                StoreListingMedia::updateOrCreate(
                    [
                        'listing_id' => $listing->id,
                        'is_primary' => true,
                    ],
                    [
                        'media_type' => StoreListingMedia::TYPE_PHOTO,
                        'file_path' => $s['image'],
                        'title' => $s['title'],
                        'sort_order' => 1,
                        'status' => StoreListingMedia::STATUS_APPROVED,
                    ]
                );

                // Add Additional Gallery Media
                if (! empty($s['gallery'])) {
                    foreach ($s['gallery'] as $gIdx => $galleryUrl) {
                        StoreListingMedia::updateOrCreate(
                            [
                                'listing_id' => $listing->id,
                                'file_path' => $galleryUrl,
                            ],
                            [
                                'media_type' => StoreListingMedia::TYPE_PHOTO,
                                'title' => $s['title'].' - Photo '.($gIdx + 2),
                                'sort_order' => $gIdx + 2,
                                'is_primary' => false,
                                'status' => StoreListingMedia::STATUS_APPROVED,
                            ]
                        );
                    }
                }

                // Attach Included Amenities
                foreach ($s['included_amenities'] as $amenitySlug) {
                    if (isset($amenityMap[$amenitySlug])) {
                        StoreListingAmenity::updateOrCreate(
                            [
                                'listing_id' => $listing->id,
                                'amenity_id' => $amenityMap[$amenitySlug]->id,
                            ],
                            [
                                'is_included' => true,
                                'notes' => null,
                            ]
                        );
                    }
                }

                // Attach Excluded Amenities with Add-on notes
                foreach ($s['excluded_amenities'] as $ex) {
                    if (isset($amenityMap[$ex['slug']])) {
                        StoreListingAmenity::updateOrCreate(
                            [
                                'listing_id' => $listing->id,
                                'amenity_id' => $amenityMap[$ex['slug']]->id,
                            ],
                            [
                                'is_included' => false,
                                'notes' => $ex['notes'],
                            ]
                        );
                    }
                }
            }
        }
    }
}

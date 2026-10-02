<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StoreAmenity;
use Illuminate\Database\Seeder;

final class StoreAmenitySeeder extends Seeder
{
    /**
     * @var list<array{category: string, slug: string, name: string, icon: string, sort_order: int}>
     */
    public const AMENITIES = [
        // Power & Climate
        [
            'category' => 'power_climate',
            'slug' => 'generator_standby',
            'name' => 'Standby Diesel Generator',
            'icon' => 'zap',
            'sort_order' => 1,
        ],
        [
            'category' => 'power_climate',
            'slug' => 'central_ac',
            'name' => 'Central Air Conditioning',
            'icon' => 'wind',
            'sort_order' => 2,
        ],
        [
            'category' => 'power_climate',
            'slug' => 'solar_inverter',
            'name' => 'Solar Inverter Backup',
            'icon' => 'sun',
            'sort_order' => 3,
        ],

        // Furniture
        [
            'category' => 'furniture',
            'slug' => 'banquet_chairs',
            'name' => 'Banquet Chairs',
            'icon' => 'armchair',
            'sort_order' => 10,
        ],
        [
            'category' => 'furniture',
            'slug' => 'round_tables',
            'name' => 'Round Banquet Tables',
            'icon' => 'circle',
            'sort_order' => 11,
        ],
        [
            'category' => 'furniture',
            'slug' => 'cocktail_tables',
            'name' => 'High Cocktail Tables',
            'icon' => 'glass-water',
            'sort_order' => 12,
        ],
        [
            'category' => 'furniture',
            'slug' => 'podium',
            'name' => 'Speaker Podium / Lectern',
            'icon' => 'mic-2',
            'sort_order' => 13,
        ],
        [
            'category' => 'furniture',
            'slug' => 'stage',
            'name' => 'Elevated Stage / Platform',
            'icon' => 'layers',
            'sort_order' => 14,
        ],

        // Audio & Visual Tech
        [
            'category' => 'av_tech',
            'slug' => 'wireless_mics',
            'name' => 'Wireless Microphones',
            'icon' => 'mic',
            'sort_order' => 20,
        ],
        [
            'category' => 'av_tech',
            'slug' => 'pa_sound_system',
            'name' => 'PA Sound System & Speakers',
            'icon' => 'volume-2',
            'sort_order' => 21,
        ],
        [
            'category' => 'av_tech',
            'slug' => 'projector_screens',
            'name' => 'Projector & Motorized Screens',
            'icon' => 'projector',
            'sort_order' => 22,
        ],
        [
            'category' => 'av_tech',
            'slug' => 'led_video_wall',
            'name' => 'LED Video Display Wall',
            'icon' => 'tv',
            'sort_order' => 23,
        ],
        [
            'category' => 'av_tech',
            'slug' => 'wifi_high_speed',
            'name' => 'High-Speed Guest Wi-Fi',
            'icon' => 'wifi',
            'sort_order' => 24,
        ],
        [
            'category' => 'av_tech',
            'slug' => 'stage_lighting',
            'name' => 'Stage & Ambient Lighting',
            'icon' => 'lightbulb',
            'sort_order' => 25,
        ],

        // Facilities & Access
        [
            'category' => 'facilities',
            'slug' => 'executive_restrooms',
            'name' => 'Executive Restrooms',
            'icon' => 'bath',
            'sort_order' => 30,
        ],
        [
            'category' => 'facilities',
            'slug' => 'parking_on_site',
            'name' => 'Secure On-Site Parking',
            'icon' => 'car',
            'sort_order' => 31,
        ],
        [
            'category' => 'facilities',
            'slug' => 'vip_green_room',
            'name' => 'VIP / Speaker Green Room',
            'icon' => 'door-closed',
            'sort_order' => 32,
        ],
        [
            'category' => 'facilities',
            'slug' => 'loading_dock',
            'name' => 'Direct Loading Dock Access',
            'icon' => 'truck',
            'sort_order' => 33,
        ],
        [
            'category' => 'facilities',
            'slug' => 'security_guards',
            'name' => 'On-Site Security Guards',
            'icon' => 'shield',
            'sort_order' => 34,
        ],
        [
            'category' => 'facilities',
            'slug' => 'wheelchair_access',
            'name' => 'Wheelchair Accessible / Ramps',
            'icon' => 'accessibility',
            'sort_order' => 35,
        ],

        // Catering Rules
        [
            'category' => 'catering_rules',
            'slug' => 'in_house_catering',
            'name' => 'In-House Catering Available',
            'icon' => 'utensils',
            'sort_order' => 40,
        ],
        [
            'category' => 'catering_rules',
            'slug' => 'outside_catering_allowed',
            'name' => 'Outside Catering Permitted',
            'icon' => 'cooking-pot',
            'sort_order' => 41,
        ],
        [
            'category' => 'catering_rules',
            'slug' => 'kitchen_prep_area',
            'name' => 'Kitchen Prep & Warming Station',
            'icon' => 'chef-hat',
            'sort_order' => 42,
        ],
    ];

    public function run(): void
    {
        foreach (self::AMENITIES as $item) {
            StoreAmenity::updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'category' => $item['category'],
                    'name' => $item['name'],
                    'icon' => $item['icon'],
                    'sort_order' => $item['sort_order'],
                ]
            );
        }
    }
}

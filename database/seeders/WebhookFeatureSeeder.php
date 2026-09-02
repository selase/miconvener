<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Seeder;

final class WebhookFeatureSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, description: string, type: string, packages: array<string, mixed>}>
     */
    private array $features = [
        'custom_webhooks' => [
            'name' => 'Custom Webhooks',
            'description' => 'Outbound webhooks for meeting and task events with Zapier-compatible format.',
            'type' => 'boolean',
            'packages' => ['business' => true, 'enterprise' => true],
        ],
    ];

    public function run(): void
    {
        foreach ($this->features as $slug => $config) {
            $feature = Feature::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $config['name'],
                    'description' => $config['description'],
                    'type' => $config['type'],
                ]
            );

            $this->command->info("Feature [{$slug}] created/verified.");

            foreach ($config['packages'] as $packageSlug => $value) {
                $package = Package::where('slug', $packageSlug)->first();

                if (! $package) {
                    continue;
                }

                if (! $package->features()->where('slug', $slug)->exists()) {
                    $package->features()->attach($feature->id, ['value' => $value]);
                    $this->command->info("  Attached [{$slug}] to [{$package->name}] with value [{$value}].");
                }
            }
        }
    }
}

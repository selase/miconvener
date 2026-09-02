<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Seeder;

final class ChatFeatureSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, description: string, type: string, packages: array<string, mixed>}>
     */
    private array $features = [
        'team_communication' => [
            'name' => 'Team Communication',
            'description' => 'Slack and Microsoft Teams integration for meeting minutes and task notifications.',
            'type' => 'boolean',
            'packages' => ['pro' => true, 'enterprise' => true],
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

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Seeder;

final class CustomDomainFeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create the Feature
        $feature = Feature::firstOrCreate(
            ['slug' => 'custom-domains'],
            [
                'name' => 'Custom Domains',
                'description' => 'Allow tenants to use their own custom domain (e.g. app.client.com).',
                'type' => 'boolean', // It's on or off
            ]
        );

        $this->command->info('Feature [custom-domains] created/verified.');

        // 2. Attach to "Scale" and "Enterprise" packages if they exist
        // You can adjust this based on your actual package names
        $packages = Package::whereIn('slug', ['scale', 'enterprise'])->get();

        foreach ($packages as $package) {
            if (! $package->features()->where('slug', 'custom-domains')->exists()) {
                $package->features()->attach($feature->id, ['value' => true]); // true for boolean
                $this->command->info("Attached [custom-domains] to package [{$package->name}].");
            }
        }
    }
}

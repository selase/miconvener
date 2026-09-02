<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class PackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Ensure basic features exist
        // These are standard SaaS limits usually
        $features = [
            'projects-limit' => ['name' => 'Projects Limit', 'type' => 'limit', 'description' => 'Maximum number of projects'],
            'users-limit' => ['name' => 'Users Limit', 'type' => 'limit', 'description' => 'Maximum number of team members'],
            'file-retention' => ['name' => 'File Retention (Days)', 'type' => 'limit', 'description' => 'Days to keep files'],
            'analytics' => ['name' => 'Analytics', 'type' => 'boolean', 'description' => 'Access to analytics dashboard'],
            'priority-support' => ['name' => 'Priority Support', 'type' => 'boolean', 'description' => 'Priority email support'],
            'custom-alerts' => ['name' => 'Custom Alerts', 'type' => 'boolean', 'description' => 'Custom notification alerts'],
            'sso' => ['name' => 'SSO Integration', 'type' => 'boolean', 'description' => 'Single Sign-On Support'],
            // custom-domains is handled by its own seeder or logic, but we make sure it exists here too if needed recursively
            'custom-domains' => ['name' => 'Custom Domains', 'type' => 'boolean', 'description' => 'Use your own domain'],
            'commerce' => ['name' => 'Commerce / Finance', 'type' => 'boolean', 'description' => 'Collect payments from your own customers'],
        ];

        foreach ($features as $slug => $data) {
            Feature::firstOrCreate(['slug' => $slug], $data);
        }

        // 2. Sync Plans from Config to Database
        // We look at config('product-page.plans') which drives the landing page
        $plans = config('product-page.plans');

        foreach ($plans as $planData) {
            $slug = Str::slug($planData['name']);

            $package = Package::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $planData['name'],
                    'description' => $planData['description'],
                    'price' => $planData['monthly_price'], // Storing monthly price as base price
                    'interval' => 'month', // Default to monthly
                    'billing_model' => Package::BILLING_MODEL_FLAT_RATE, // Simplified assumption
                    'is_active' => true,
                ]
            );

            // 3. Assign Features based on plan level
            // This is a rough mapping based on the plan names "Starter", "Pro", "Enterprise"
            // In a real app, you might want this mapping explicit in the config, but we infer it here for seeding.

            $packageFeatures = [];

            if ($slug === 'starter') {
                $packageFeatures['projects-limit'] = ['value' => 3];
                $packageFeatures['users-limit'] = ['value' => 5]; // Implicit limit
                $packageFeatures['file-retention'] = ['value' => 1]; // 24 hours
                $packageFeatures['analytics'] = ['value' => true]; // Basic
            } elseif ($slug === 'pro') {
                $packageFeatures['projects-limit'] = ['value' => -1]; // Unlimited
                $packageFeatures['users-limit'] = ['value' => 20];
                $packageFeatures['file-retention'] = ['value' => 30]; // 30 days
                $packageFeatures['analytics'] = ['value' => true];
                $packageFeatures['priority-support'] = ['value' => true];
                $packageFeatures['custom-alerts'] = ['value' => true];
            } elseif ($slug === 'enterprise') {
                $packageFeatures['projects-limit'] = ['value' => -1];
                $packageFeatures['users-limit'] = ['value' => -1];
                $packageFeatures['file-retention'] = ['value' => -1]; // Unlimited
                $packageFeatures['analytics'] = ['value' => true];
                $packageFeatures['priority-support'] = ['value' => true];
                $packageFeatures['custom-alerts'] = ['value' => true];
                $packageFeatures['sso'] = ['value' => true];
                $packageFeatures['custom-domains'] = ['value' => true];
                $packageFeatures['commerce'] = ['value' => true];
            }

            // Sync features
            foreach ($packageFeatures as $featureSlug => $pivotData) {
                $feature = Feature::where('slug', $featureSlug)->first();
                if ($feature) {
                    // Check if already attached to avoid duplicates or use sync without detaching all others if needed
                    // For seeding, attach/sync is fine.
                    if (! $package->features()->where('feature_id', $feature->id)->exists()) {
                        $package->features()->attach($feature->id, $pivotData);
                    } else {
                        // Update existing pivot if needed
                        $package->features()->updateExistingPivot($feature->id, $pivotData);
                    }
                }
            }

            $this->command->info("Seeded Plan: {$planData['name']}");
        }
    }
}

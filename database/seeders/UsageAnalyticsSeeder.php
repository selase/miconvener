<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enum\UsageMetric;
use App\Models\Tenant;
use App\Models\UsageRollup;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

final class UsageAnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            $this->seedTenantUsage($tenant);
            $this->seedTenantResourceSnapshots($tenant);
        }
    }

    private function seedTenantResourceSnapshots(Tenant $tenant): void
    {
        $now = Carbon::now();

        // Seed last 30 days of daily snapshots for resources
        for ($i = 0; $i < 30; $i++) {
            $time = $now->copy()->subDays($i)->startOfDay();

            // Storage: Gradual growth
            $storageBase = 500 * 1024 * 1024; // 500MB
            $growth = (30 - $i) * 15 * 1024 * 1024; // 15MB growth per day

            UsageRollup::create([
                'tenant_id' => $tenant->id,
                'period' => 'day',
                'period_start' => $time,
                'metric' => UsageMetric::STORAGE_BYTES,
                'value' => $storageBase + $growth,
                'dimensions' => [],
                'dimensions_hash' => UsageRollup::hashDimensions([]),
            ]);

            // Database: Gradual growth
            $dbBase = 50 * 1024 * 1024; // 50MB
            $dbGrowth = (30 - $i) * 2 * 1024 * 1024; // 2MB growth per day

            UsageRollup::create([
                'tenant_id' => $tenant->id,
                'period' => 'day',
                'period_start' => $time,
                'metric' => UsageMetric::DB_BYTES,
                'value' => $dbBase + $dbGrowth,
                'dimensions' => [],
                'dimensions_hash' => UsageRollup::hashDimensions([]),
            ]);
        }
    }

    private function seedTenantUsage(Tenant $tenant): void
    {
        $now = Carbon::now();

        // Seed last 7 days of hourly rollups
        for ($i = 0; $i < 168; $i++) {
            $time = $now->copy()->subHours($i)->minute(0)->second(0);
            $hourSeed = random_int(10, 100);

            // 1. Requests Count
            UsageRollup::create([
                'tenant_id' => $tenant->id,
                'period' => 'hour',
                'period_start' => $time,
                'metric' => UsageMetric::REQUEST_COUNT,
                'value' => $hourSeed,
                'dimensions' => ['status_bucket' => '2xx'],
                'dimensions_hash' => UsageRollup::hashDimensions(['status_bucket' => '2xx']),
            ]);

            // Add some errors
            if (random_int(1, 10) > 8) {
                UsageRollup::create([
                    'tenant_id' => $tenant->id,
                    'period' => 'hour',
                    'period_start' => $time,
                    'metric' => UsageMetric::REQUEST_COUNT,
                    'value' => random_int(1, 5),
                    'dimensions' => ['status_bucket' => '5xx'],
                    'dimensions_hash' => UsageRollup::hashDimensions(['status_bucket' => '5xx']),
                ]);
            }

            // 2. Request Duration (simulating performance degradation at "peak" hours)
            $peakMultiplier = ($time->hour >= 14 && $time->hour <= 18) ? 2.5 : 1.0;
            UsageRollup::create([
                'tenant_id' => $tenant->id,
                'period' => 'hour',
                'period_start' => $time,
                'metric' => UsageMetric::REQUEST_DURATION_MS,
                'value' => (random_int(50, 200) * $peakMultiplier) * $hourSeed, // Sum of durations
                'dimensions' => [],
                'dimensions_hash' => UsageRollup::hashDimensions([]),
            ]);

            // 3. Jobs Count
            UsageRollup::create([
                'tenant_id' => $tenant->id,
                'period' => 'hour',
                'period_start' => $time,
                'metric' => UsageMetric::JOB_COUNT,
                'value' => random_int(5, 30),
                'dimensions' => [],
                'dimensions_hash' => UsageRollup::hashDimensions([]),
            ]);
        }
    }
}

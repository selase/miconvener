<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Feature;
use App\Models\Package;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class SyncMessageEntitlementsCommand extends Command
{
    protected $signature = 'community:sync-message-entitlements {--dry-run : Inspect without writing}';

    protected $description = 'Fill only missing Community message plan entitlements; preserve overrides, commercial terms and rollout switches.';

    public function handle(): int
    {
        $keys = ['community_message_scheduling' => ['starter', 'growth', 'enterprise'], 'community_group_messaging' => ['growth', 'enterprise']];
        if ($this->option('dry-run')) {
            $this->info('Preview only: two message entitlement definitions, '.Package::query()->count().' packages and '.Tenant::query()->count().' organisations inspected. No writes. Existing values will be preserved.');

            return self::SUCCESS;
        }
        foreach ($keys as $key => $plans) {
            $feature = Feature::query()->firstOrCreate(['slug' => $key], ['name' => $key === 'community_message_scheduling' ? 'Scheduled Community Messages' : 'Community Group Messaging', 'type' => 'boolean', 'description' => 'Community announcement entitlement.']);
            Package::query()->eachById(function (Package $package) use ($feature, $plans): void {
                if (! $package->features()->whereKey($feature->id)->exists() && in_array($package->slug, ['free', 'starter', 'growth', 'enterprise'], true)) {
                    $package->features()->attach($feature->id, ['value' => in_array($package->slug, $plans, true) ? '1' : '0']);
                }
            });
            Tenant::query()->with('package.features')->eachById(function (Tenant $tenant) use ($key, $plans): void {
                $packageFeature = $tenant->package?->features->firstWhere('slug', $key);
                $value = $packageFeature?->getRelationValue('pivot')?->getAttribute('value');
                $enabled = $packageFeature === null ? in_array($tenant->package?->slug, $plans, true) : filter_var($value, FILTER_VALIDATE_BOOLEAN);
                $tenant->features()->firstOrCreate(['feature_key' => $key], ['enabled' => $enabled, 'meta' => ['type' => 'boolean', 'value' => $enabled, 'source' => 'package', 'package_id' => $tenant->package_id]]);
            });
        }
        $this->info('Missing message entitlements filled. Rollout switches and existing values were preserved.');

        return self::SUCCESS;
    }
}

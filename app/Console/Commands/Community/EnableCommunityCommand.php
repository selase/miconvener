<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The Community rollout switch: an admin-override feature row, so the nightly
 * plan sync never turns it off. Switching it off stops new community activity
 * only; money already held stays reachable (see Tenant::hasDuesMoney()).
 */
final class EnableCommunityCommand extends Command
{
    protected $signature = 'community:enable {tenant : The tenant slug} {--off : Switch Community off instead of on}';

    protected $description = 'Switch the Community section on or off for a tenant (admin-override feature row).';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$this->argument('tenant')}\".");

            return self::FAILURE;
        }

        $enabled = ! $this->option('off');

        $meta = $tenant->features()->where('feature_key', 'community')->first()->meta ?? [];
        $meta['source'] = 'admin_override';

        $tenant->features()->updateOrCreate(
            ['feature_key' => 'community'],
            ['enabled' => $enabled, 'meta' => $meta],
        );
        // FeatureService::enabled() (the `feature` middleware and @feature) caches this key.
        Cache::forget("tenant_{$tenant->id}_feature_community");

        $this->info('Community '.($enabled ? 'enabled' : 'disabled')." for {$tenant->slug}.");

        return self::SUCCESS;
    }
}

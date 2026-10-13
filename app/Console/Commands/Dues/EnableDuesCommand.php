<?php

declare(strict_types=1);

namespace App\Console\Commands\Dues;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableDuesCommand extends Command
{
    protected $signature = 'dues:enable {tenant : The tenant slug} {--off : Switch dues off instead of on}';

    protected $description = 'Dark-launch member dues for a tenant by toggling its member_dues feature as an admin override.';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$this->argument('tenant')}\".");

            return self::FAILURE;
        }

        $enabled = ! $this->option('off');

        $meta = $tenant->features()->where('feature_key', 'member_dues')->first()->meta ?? [];
        $meta['source'] = 'admin_override';

        $tenant->features()->updateOrCreate(
            ['feature_key' => 'member_dues'],
            ['enabled' => $enabled, 'meta' => $meta],
        );
        // FeatureService::enabled() (the `feature` middleware and @feature) caches this key.
        Cache::forget("tenant_{$tenant->id}_feature_member_dues");

        $this->info('Member dues '.($enabled ? 'enabled' : 'disabled')." for {$tenant->slug}.");

        return self::SUCCESS;
    }
}

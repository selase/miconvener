<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableGivingCommand extends Command
{
    protected $signature = 'community:enable-giving {tenant : Organisation slug} {--off} {--online : Explicitly approve online readiness}';

    protected $description = 'Enable Giving for one organisation; online readiness is a separate opt-in.';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        if ($tenant === null || (! $this->option('off') && ! $tenant->communityEnabled())) {
            $this->error('Organisation not found or Community is disabled.');

            return self::FAILURE;
        }
        if ($this->option('off') && $this->option('online')) {
            $this->error('Choose enable or off, not both.');

            return self::FAILURE;
        }
        Feature::query()->firstOrCreate(['slug' => 'community_giving'], ['name' => 'Community Giving', 'type' => 'boolean']);
        $row = $tenant->features()->firstOrNew(['feature_key' => 'community_giving']);
        $meta = $row->meta ?? [];
        $meta['source'] = 'admin_override';
        $meta['online_enabled'] = $this->option('online') ? true : ($meta['online_enabled'] ?? false);
        $row->fill(['enabled' => ! $this->option('off'), 'meta' => $meta])->save();
        Cache::forget('tenant_'.$tenant->id.'_feature_community_giving');
        $this->info('Giving '.($this->option('off') ? 'disabled' : 'enabled').' for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}

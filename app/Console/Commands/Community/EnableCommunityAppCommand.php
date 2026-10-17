<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableCommunityAppCommand extends Command
{
    protected $signature = 'community:enable-app {tenant : Organisation slug} {--off : Disable the personal app rollout}';

    protected $description = 'Enable or disable the personal app layout for one organisation.';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }
        Feature::query()->firstOrCreate(['slug' => 'community_app'], ['name' => 'Personal app', 'type' => 'boolean', 'description' => 'Community milestone 4 app presentation rollout.']);
        $tenant->features()->updateOrCreate(['feature_key' => 'community_app'], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$tenant->id.'_feature_community_app');
        $this->info('Personal app '.($this->option('off') ? 'disabled' : 'enabled').' for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}

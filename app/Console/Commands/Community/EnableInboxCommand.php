<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableInboxCommand extends Command
{
    protected $signature = 'community:enable-inbox {tenant : Organisation slug} {--off : Disable the community inbox rollout}';

    protected $description = 'Enable or disable the community inbox layout for one organisation.';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }
        Feature::query()->firstOrCreate(['slug' => 'community_inbox'], ['name' => 'Community inbox', 'type' => 'boolean', 'description' => 'Community milestone 4b inbox rollout; requires community_app.']);
        $tenant->features()->updateOrCreate(['feature_key' => 'community_inbox'], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$tenant->id.'_feature_community_inbox');
        $this->info('Community inbox '.($this->option('off') ? 'disabled' : 'enabled').' for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}

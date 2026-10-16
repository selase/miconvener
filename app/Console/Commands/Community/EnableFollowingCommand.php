<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableFollowingCommand extends Command
{
    protected $signature = 'community:following-enable {tenant : Organisation slug} {--off : Disable the milestone rollout}';

    protected $description = 'Enable or disable Following for one organisation.';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }
        Feature::query()->firstOrCreate(['slug' => 'community_following'], ['name' => 'Following', 'type' => 'boolean', 'description' => 'Free independent Following rollout.']);
        $tenant->features()->updateOrCreate(['feature_key' => 'community_following'], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$tenant->id.'_feature_community_following');
        $this->info('Following '.($this->option('off') ? 'disabled' : 'enabled').' for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}

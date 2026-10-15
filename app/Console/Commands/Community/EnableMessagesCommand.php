<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableMessagesCommand extends Command
{
    protected $signature = 'community:messages-enable {tenant : Organisation slug} {--off : Disable the milestone rollout}';

    protected $description = 'Enable or disable Community groups and messages for one organisation.';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }
        $tenant->features()->updateOrCreate(['feature_key' => 'community_messages'], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$tenant->id.'_feature_community_messages');
        $this->info('Community messages '.($this->option('off') ? 'disabled' : 'enabled').' for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}

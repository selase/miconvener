<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Feature;
use App\Models\Tenant;
use App\Services\Marketplace\VenueRequestRollout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableVenueRequestsCommand extends Command
{
    protected $signature = 'venue:enable-requests {tenant : Organisation slug} {--off : Stop taking approval-first venue requests}';

    protected $description = 'Enable or disable approval-first public venue requests for one organisation.';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }

        Feature::query()->firstOrCreate(['slug' => VenueRequestRollout::FEATURE], [
            'name' => 'Venue booking requests',
            'type' => 'boolean',
            'description' => 'Approval-first public venue requests; the host approves a bounded hold. Takes no payment.',
        ]);
        $tenant->features()->updateOrCreate(['feature_key' => VenueRequestRollout::FEATURE], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$tenant->id.'_feature_'.VenueRequestRollout::FEATURE);
        $this->info('Venue requests '.($this->option('off') ? 'disabled' : 'enabled').' for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use App\Models\Feature;
use App\Models\Tenant;
use App\Services\Events\Profiles\ProfileRollout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableAttendeeProfilesCommand extends Command
{
    protected $signature = 'events:enable-attendee-profiles {tenant : Organisation slug} {--off : Disable public profiles}';

    protected $description = 'Enable the optional attendee-profile rollout for one organisation, without publishing anyone.';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }
        if (! $this->option('off') && (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp'))) {
            $this->error('GD image decoding and WebP encoding are required before enabling profiles.');

            return self::FAILURE;
        }
        Feature::query()->firstOrCreate(['slug' => ProfileRollout::FEATURE], ['name' => 'Optional public attendee profiles', 'type' => 'boolean', 'description' => 'Explicit self-consent only; no automatic publication.']);
        $tenant->features()->updateOrCreate(['feature_key' => ProfileRollout::FEATURE], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$tenant->id.'_feature_'.ProfileRollout::FEATURE);
        $this->info('Attendee profile rollout '.($this->option('off') ? 'disabled' : 'enabled').' for '.$tenant->slug.'. Attendees remain private until they opt in.');

        return self::SUCCESS;
    }
}

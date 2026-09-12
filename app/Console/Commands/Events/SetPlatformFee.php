<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class SetPlatformFee extends Command
{
    protected $signature = 'events:set-platform-fee {tenant_slug} {percentage} {--event=} {--cap=} {--clear}';

    protected $description = 'Superadmin-only: set the platform fee percentage and per-ticket cap for a tenant (default for all their events) or a single event override.';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant_slug'))->firstOrFail();
        $clearing = (bool) $this->option('clear');
        $percentage = $clearing ? null : (float) $this->argument('percentage');

        /**
         * The cap is in minor units. Null clears it, which falls through to
         * the package default rather than meaning "uncapped".
         */
        $cap = $clearing || $this->option('cap') === null
            ? null
            : (int) $this->option('cap');

        $attributes = ['platform_fee_percentage' => $percentage];

        if ($clearing || $this->option('cap') !== null) {
            $attributes['platform_fee_cap_amount'] = $cap;
        }

        $capNote = array_key_exists('platform_fee_cap_amount', $attributes)
            ? ', cap '.($cap === null ? 'cleared' : $cap.' minor units')
            : '';

        $eventSlug = $this->option('event');

        if ($eventSlug) {
            $event = Event::where('tenant_id', $tenant->id)->where('slug', $eventSlug)->firstOrFail();
            $event->update($attributes);
            $this->info("Event '{$event->name}' fee override: ".($percentage === null ? 'cleared, falls back to tenant default' : "{$percentage}%").$capNote);

            return self::SUCCESS;
        }

        $tenant->update($attributes);
        $this->info("Tenant '{$tenant->name}' default fee: ".($percentage === null ? 'cleared, falls back to global default' : "{$percentage}%").$capNote);

        return self::SUCCESS;
    }
}

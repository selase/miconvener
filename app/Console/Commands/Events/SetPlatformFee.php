<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class SetPlatformFee extends Command
{
    protected $signature = 'events:set-platform-fee {tenant_slug} {percentage} {--event=} {--clear}';

    protected $description = 'Superadmin-only: set the platform fee percentage for a tenant (default for all their events) or a single event override.';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant_slug'))->firstOrFail();
        $percentage = $this->option('clear') ? null : (float) $this->argument('percentage');

        $eventSlug = $this->option('event');

        if ($eventSlug) {
            $event = Event::where('tenant_id', $tenant->id)->where('slug', $eventSlug)->firstOrFail();
            $event->update(['platform_fee_percentage' => $percentage]);
            $this->info("Event '{$event->name}' fee override: ".($percentage === null ? 'cleared, falls back to tenant default' : "{$percentage}%"));

            return self::SUCCESS;
        }

        $tenant->update(['platform_fee_percentage' => $percentage]);
        $this->info("Tenant '{$tenant->name}' default fee: ".($percentage === null ? 'cleared, falls back to global default' : "{$percentage}%"));

        return self::SUCCESS;
    }
}

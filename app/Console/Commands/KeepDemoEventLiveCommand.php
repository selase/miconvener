<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventSession;
use App\Models\Tenant;
use Database\Seeders\ProbeWorkspaceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the demo event (ProbeWorkspaceSeeder) running whenever someone opens
 * it. When its window is about to close, the event, its sessions and its
 * material release times move forward together, so it runs from two hours
 * ago to six hours ahead again. Only times move: decks, check-ins and other
 * demo progress are left alone, unlike a re-seed.
 */
final class KeepDemoEventLiveCommand extends Command
{
    protected $signature = 'demo:keep-live';

    protected $description = 'Move the demo event forward in time when its window is about to close';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', (string) config('attendee_portal.probe.tenant_slug'))->first();
        $event = $tenant === null ? null : Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', ProbeWorkspaceSeeder::EVENT_SLUG)
            ->first();

        if ($event === null) {
            $this->info('No demo event to keep live.');

            return self::SUCCESS;
        }

        // Still comfortably running: leave it, so a demo in progress never jumps.
        if ($event->starts_at->lte(now()) && $event->ends_at->gt(now()->addHours(2))) {
            $this->info('Demo event is live until '.$event->ends_at->toDateTimeString().'.');

            return self::SUCCESS;
        }

        $seconds = (int) $event->starts_at->diffInSeconds(now()->subHours(2), false);

        DB::connection('landlord')->transaction(function () use ($event, $seconds): void {
            $event->forceFill([
                'starts_at' => $event->starts_at->addSeconds($seconds),
                'ends_at' => $event->ends_at->addSeconds($seconds),
            ])->save();

            EventSession::query()->where('event_id', $event->id)->get()->each(function (EventSession $session) use ($seconds): void {
                $session->forceFill([
                    'starts_at' => $session->starts_at->addSeconds($seconds),
                    'ends_at' => $session->ends_at->addSeconds($seconds),
                ])->save();
            });

            EventMaterial::query()->where('event_id', $event->id)->whereNotNull('release_at')->get()
                ->each(fn (EventMaterial $material) => $material->forceFill([
                    'release_at' => $material->release_at?->addSeconds($seconds),
                ])->save());
        });

        $this->info('Demo event moved forward; it now runs until '.$event->fresh()?->ends_at->toDateTimeString().'.');

        return self::SUCCESS;
    }
}

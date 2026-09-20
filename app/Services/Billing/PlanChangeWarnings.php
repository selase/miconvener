<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\Package;
use App\Models\Tenant;

/**
 * What a tenant stands to lose by moving to a smaller plan.
 *
 * Nothing here blocks the change: an organizer may well want it. The point is
 * that they are told first, in terms of what they actually have -- this many
 * registrations against the new ceiling, these published events selling paid
 * tickets -- rather than discovering it when a registration is turned away.
 */
final class PlanChangeWarnings
{
    /**
     * @return list<string> empty when the target plan costs them nothing
     */
    public function for(Tenant $tenant, Package $target): array
    {
        $target->loadMissing('features');

        $warnings = [];

        $registrationLimit = $this->featureValue($target, 'event_registrations');
        if ($registrationLimit !== null && $registrationLimit >= 0) {
            $used = (int) $tenant->usage()
                ->where('feature_slug', 'event_registrations')
                ->whereNull('period_start')
                ->value('used_count');

            if ($used >= $registrationLimit) {
                $warnings[] = "You have {$used} registrations this month and {$target->name} allows {$registrationLimit}. "
                    .'New registrations will be turned away until the count resets at the start of next month.';
            }
        }

        if (! $this->allows($target, 'paid_tickets')) {
            $paidEvents = Event::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', Event::STATUS_PUBLISHED)
                ->where(fn ($query) => $query
                    ->where('ticket_price', '>', 0)
                    ->orWhereHas('ticketTypes', fn ($tickets) => $tickets->where('is_active', true)->where('price', '>', 0))
                )
                ->count();

            if ($paidEvents > 0) {
                $warnings[] = "{$paidEvents} published event(s) sell paid tickets. On {$target->name} you cannot add or change "
                    .'paid ticket types. Tickets already on sale keep selling, so an event in progress will not break.';
            }
        }

        if (! $this->allows($target, 'event_materials')) {
            $materials = EventMaterial::query()->where('tenant_id', $tenant->id)->count();

            if ($materials > 0) {
                $warnings[] = "{$materials} speaker material file(s) are attached to your events. On {$target->name} you cannot "
                    .'upload new ones. Files already uploaded stay available to attendees.';
            }
        }

        return $warnings;
    }

    private function featureValue(Package $package, string $slug): ?int
    {
        $feature = $package->features->firstWhere('slug', $slug);

        return $feature === null ? null : (int) data_get($feature, 'pivot.value');
    }

    private function allows(Package $package, string $slug): bool
    {
        $feature = $package->features->firstWhere('slug', $slug);

        // A package that does not list the feature at all predates it; treat that
        // as permitted, matching how the runtime gates read a missing row.
        return $feature === null || filter_var(data_get($feature, 'pivot.value'), FILTER_VALIDATE_BOOLEAN);
    }
}

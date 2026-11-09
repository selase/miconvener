<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Event;
use App\Models\OrganisationPayoutPolicy;
use App\Models\ScheduledPayoutProposal;
use App\Services\Marketplace\CommerceInventoryLocks;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ScheduledPayoutPlanner
{
    /** @return list<ScheduledPayoutProposal> */
    public function prepare(OrganisationPayoutPolicy $policy, CarbonImmutable $now): array
    {
        return $policy->getConnection()->transaction(function () use ($policy, $now): array {
            app(CommerceInventoryLocks::class)->tenant($policy->tenant_id);
            $current = OrganisationPayoutPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            try {
                app(PayoutPolicies::class)->assertActive($current);
                $local = $now->setTimezone($current->timezone);
                $due = $local->startOfDay()->setTimeFromTimeString($current->local_time);
                if ($current->cadence === 'weekly') {
                    $due = $due->subDays(($local->dayOfWeek - $current->weekday + 7) % 7);
                }
                if ($now->lt($due) || $now->gte($due->addDay()) || $due->lt($current->active_after)) {
                    return [];
                }
                $events = $current->category === 'event'
                    ? Event::withoutGlobalScopes()->where('tenant_id', $current->tenant_id)->where('currency', 'GHS')->orderBy('id')->pluck('id')->all()
                    : [null];
                $results = [];
                foreach ($events as $eventId) {
                    $snapshot = app(PayoutEligibility::class)->inspect($current, $eventId);
                    if (! $snapshot['eligible']) {
                        continue;
                    }
                    $results[] = ScheduledPayoutProposal::query()->firstOrCreate(['policy_id' => $current->id, 'scope_key' => $eventId ?? $current->category,
                        'window_key' => $due->format('Y-m-d')], ['tenant_id' => $current->tenant_id, 'event_id' => $eventId,
                            'snapshot' => $snapshot, 'amount' => $snapshot['amount'], 'expires_at' => $due->addDay(), 'status' => 'proposed']);
                }

                return $results;
            } catch (ValidationException) {
                return [];
            }
        });
    }
}

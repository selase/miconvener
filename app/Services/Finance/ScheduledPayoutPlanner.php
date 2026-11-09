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
                $due = app(PayoutScheduleWindow::class)->due($current, $now);
                if ($now->lt($due) || $now->gte(app(PayoutScheduleWindow::class)->expiresAt($due)) || $due->lt($current->active_after)) {
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
                        'window_key' => $due->format('Y-m-d'), 'revision' => 1], ['tenant_id' => $current->tenant_id, 'event_id' => $eventId,
                            'snapshot' => $snapshot, 'amount' => $snapshot['amount'], 'expires_at' => app(PayoutScheduleWindow::class)->expiresAt($due), 'status' => 'proposed']);
                }

                foreach ($results as $proposal) {
                    app(ScheduledPayoutNotices::class)->enqueue($current, 'proposal_ready', $proposal->id);
                }

                return $results;
            } catch (ValidationException) {
                return [];
            }
        });
    }

    public function refreshProposal(ScheduledPayoutProposal $proposal, \App\Models\User $actor): ScheduledPayoutProposal
    {
        return $proposal->getConnection()->transaction(function () use ($proposal, $actor): ScheduledPayoutProposal {
            app(CommerceInventoryLocks::class)->tenant($proposal->tenant_id);
            $current = ScheduledPayoutProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if (now()->gte($current->expires_at)) {
                throw ValidationException::withMessages(['schedule' => 'This scheduled window expired. Wait for the next run or use an existing manual payout.']);
            }
            $policy = OrganisationPayoutPolicy::query()->findOrFail($current->policy_id);
            app(ScheduledPayoutApproval::class)->cancel($current, $actor);
            $latest = ScheduledPayoutProposal::query()->where('policy_id', $policy->id)->where('scope_key', $current->scope_key)->where('window_key', $current->window_key)->orderByDesc('revision')->firstOrFail();
            if ($latest->id !== $current->id) {
                return $latest;
            }
            $snapshot = app(PayoutEligibility::class)->inspect($policy, $current->event_id);
            if (! $snapshot['eligible']) {
                throw ValidationException::withMessages(['schedule' => $snapshot['reason']]);
            }
            $revision = 1 + (int) ScheduledPayoutProposal::query()->where('policy_id', $policy->id)->where('scope_key', $current->scope_key)->where('window_key', $current->window_key)->max('revision');
            $fresh = ScheduledPayoutProposal::query()->create(['tenant_id' => $current->tenant_id, 'policy_id' => $policy->id, 'event_id' => $current->event_id,
                'scope_key' => $current->scope_key, 'window_key' => $current->window_key, 'revision' => $revision,
                'snapshot' => $snapshot, 'amount' => $snapshot['amount'], 'expires_at' => $current->expires_at, 'status' => 'proposed']);
            app(PayoutPolicies::class)->record($policy, $actor, 'proposal_refreshed', ['proposal_id' => $fresh->id, 'previous_proposal_id' => $current->id]);

            return $fresh;
        });
    }
}

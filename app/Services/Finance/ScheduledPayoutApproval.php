<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\EventPayout;
use App\Models\OrganisationPayoutPolicy;
use App\Models\ScheduledPayoutProposal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\CommerceInventoryLocks;
use App\Services\Marketplace\StorePayouts;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ScheduledPayoutApproval
{
    public function reserve(ScheduledPayoutProposal $proposal, User $actor, string $operationKey): EventPayout
    {
        if (! Str::isUuid($operationKey)) {
            $this->refuse('Use a valid approval operation key.');
        }

        return $proposal->getConnection()->transaction(function () use ($proposal, $actor, $operationKey): EventPayout {
            [$current, $policy, $tenant] = $this->locked($proposal);
            $this->authorise($tenant, $actor, $policy);
            app(PayoutPolicies::class)->assertActive($policy);
            if ($current->status === 'approved' && $current->operation_key === $operationKey && $current->payout_id !== null) {
                return EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($current->payout_id);
            }
            if ($current->status !== 'proposed' || now()->gte($current->expires_at)) {
                $this->refuse('This proposal expired or changed. Review a fresh proposal.');
            }
            $snapshot = app(PayoutEligibility::class)->inspect($policy, $current->event_id);
            if (! $snapshot['eligible'] || $current->amount > $snapshot['available'] || $snapshot['fee'] !== $current->snapshot['fee'] || $snapshot['terms_fingerprint'] !== $current->snapshot['terms_fingerprint']) {
                $this->refuse('Funds or transfer fees changed. Review a fresh proposal.');
            }
            $this->assertLimits($policy, $current);
            $data = ['amount' => $current->amount, 'currency' => 'GHS', 'payout_account_id' => $policy->payout_account_id,
                'operation_key' => $operationKey, 'note' => 'Human-approved scheduled payout proposal '.$current->id];
            $payout = match ($policy->category) {
                'dues', 'giving' => app(CommunityPayouts::class)->reserve($tenant, $actor, LedgerCategoryEnum::from($policy->category), $data),
                'commerce' => app(StorePayouts::class)->reserve($tenant, $actor, $data),
                'event' => $this->reserveEvent($current, $policy),
                default => throw ValidationException::withMessages(['schedule' => 'Unsupported payout scope.']),
            };
            $current->update(['status' => 'approved', 'approved_by' => $actor->id, 'payout_id' => $payout->id,
                'operation_key' => $operationKey, 'submit_before' => now()->addMinutes(30)]);
            app(PayoutPolicies::class)->record($policy, $actor, 'payout_reserved', ['proposal_id' => $current->id, 'payout_id' => $payout->id, 'amount' => $payout->amount]);

            return $payout;
        });
    }

    /** @return array<string, mixed> */
    public function submit(ScheduledPayoutProposal $proposal, User $actor): array
    {
        $policy = OrganisationPayoutPolicy::query()->findOrFail($proposal->policy_id);
        $tenant = Tenant::query()->findOrFail($policy->tenant_id);
        $this->authorise($tenant, $actor, $policy);
        app(PayoutPolicies::class)->assertActive($policy);
        app(ProviderLiquidity::class)->refresh($policy->account_fingerprint, 'GHS');

        $payout = $proposal->getConnection()->transaction(function () use ($proposal, $actor): EventPayout {
            [$current, $policy, $tenant] = $this->locked($proposal);
            $this->authorise($tenant, $actor, $policy);
            app(PayoutPolicies::class)->assertActive($policy);
            if ($current->status !== 'approved' || $current->submit_before === null || now()->gte($current->submit_before) || $current->payout_id === null) {
                $this->refuse('This approval expired or was already submitted. Refresh and review its current state.');
            }
            $snapshot = app(PayoutEligibility::class)->inspect($policy, $current->event_id, $current->payout_id);
            if ($current->amount > $snapshot['available'] || $snapshot['fee'] !== $current->snapshot['fee'] || $snapshot['terms_fingerprint'] !== $current->snapshot['terms_fingerprint']) {
                $this->refuse('Refunds, reserves or fees changed. Cancel this unsent payout and review a fresh proposal.');
            }
            $payout = EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($current->payout_id);
            if ($payout->status !== EventPayout::STATUS_SCHEDULED || $payout->provider_reference !== null) {
                $this->refuse('This payout is already submitted. Reconcile the original transfer.');
            }

            return $payout;
        });

        return app(PayoutSubmissionGuard::class)->withHuman($payout, $actor, function () use ($payout, $policy, $tenant, $actor): array {
            return match ($policy->category) {
                'commerce' => app(StorePayouts::class)->send($payout),
                'dues', 'giving' => app(CommunityPayouts::class)->send($tenant, $actor, $payout),
                'event' => $this->sendEvent($payout),
                default => throw ValidationException::withMessages(['schedule' => 'Unsupported payout scope.']),
            };
        });
    }

    /** @return array<string, mixed> */
    public function finalize(ScheduledPayoutProposal $proposal, User $actor, string $otp, bool $platform = false): array
    {
        $policy = OrganisationPayoutPolicy::query()->findOrFail($proposal->policy_id);
        $tenant = Tenant::query()->findOrFail($policy->tenant_id);
        if ($platform) {
            app(PayoutPolicies::class)->authorisePlatform($actor);
        } else {
            $this->authorise($tenant, $actor, $policy);
        }
        app(PayoutPolicies::class)->assertDestination($policy);
        $payout = EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($proposal->payout_id);

        return app(PayoutSubmissionGuard::class)->withHuman($payout, $actor, fn (): array => match ($policy->category) {
            'commerce' => app(StorePayouts::class)->finalize($payout, $otp),
            'dues', 'giving' => app(CommunityPayouts::class)->finalize($tenant, $actor, $payout, $otp),
            default => app(PayoutRelease::class)->release($payout, $otp),
        });
    }

    public function cancel(ScheduledPayoutProposal $proposal, User $actor): void
    {
        $proposal->getConnection()->transaction(function () use ($proposal, $actor): void {
            [$current, $policy, $tenant] = $this->locked($proposal);
            $this->authorise($tenant, $actor, $policy);
            if ($current->status === 'cancelled') {
                return;
            }
            if ($current->payout_id !== null) {
                $payout = EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($current->payout_id);
                if ($payout->status !== 'scheduled' || $payout->provider_reference !== null || $payout->provider_transfer_code !== null) {
                    $this->refuse('A submitted or uncertain transfer must be reconciled, never cancelled or resent.');
                }
                $payout->update(['status' => 'cancelled']);
            } elseif ($current->status !== 'proposed') {
                $this->refuse('This proposal cannot be cancelled.');
            }
            $current->update(['status' => 'cancelled', 'reason' => 'Unsent payout cancelled by authorised staff.']);
            app(PayoutPolicies::class)->record($policy, $actor, 'payout_cancelled', ['proposal_id' => $current->id]);
        });
    }

    public function assertLimits(OrganisationPayoutPolicy $policy, ScheduledPayoutProposal $proposal): void
    {
        $start = now()->setTimezone($policy->timezone)->startOfDay()->utc();
        $base = EventPayout::withoutGlobalScopes()->where('tenant_id', $policy->tenant_id)->where('category', $policy->category)->where('currency', 'GHS')
            ->whereNotIn('status', [EventPayout::STATUS_CANCELLED, EventPayout::STATUS_FAILED]);
        if ($proposal->payout_id !== null) {
            $base->whereKeyNot($proposal->payout_id);
        }
        $payouts = $base->get(['id', 'amount', 'created_at']);
        $times = \App\Models\PayoutLiquidityAllocation::query()->whereIn('payout_id', $payouts->pluck('id'))->get()->keyBy('payout_id');
        $day = 0;
        $rolling = 0;
        foreach ($payouts as $payout) {
            $at = $times->get($payout->id)->created_at ?? $payout->created_at;
            if ($at->gte($start)) {
                $day += $payout->amount;
            }
            if ($at->gte(now()->subDay())) {
                $rolling += $payout->amount;
            }
        }
        if ($day + $proposal->amount > $policy->daily_limit || $rolling + $proposal->amount > $policy->rolling_limit) {
            $this->refuse('This payout would exceed the approved daily or rolling 24-hour limit.');
        }
    }

    /** @return array{ScheduledPayoutProposal, OrganisationPayoutPolicy, Tenant} */
    private function locked(ScheduledPayoutProposal $proposal): array
    {
        $tenant = app(CommerceInventoryLocks::class)->tenant($proposal->tenant_id);
        $current = ScheduledPayoutProposal::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($proposal->id);
        $policy = OrganisationPayoutPolicy::query()->where('tenant_id', $tenant->id)->findOrFail($current->policy_id);

        return [$current, $policy, $tenant];
    }

    private function authorise(Tenant $tenant, User $actor, OrganisationPayoutPolicy $policy): void
    {
        $policies = app(PayoutPolicies::class);
        $policies->authoriseTenant($tenant, $actor);
        $policies->authoriseTenant($tenant, $actor, $policy->category === 'commerce' ? 'manage store finance' : 'manage payouts');
    }

    private function reserveEvent(ScheduledPayoutProposal $proposal, OrganisationPayoutPolicy $policy): EventPayout
    {
        $event = Event::withoutGlobalScopes()->where('tenant_id', $policy->tenant_id)->lockForUpdate()->findOrFail($proposal->event_id);
        $snapshot = app(PayoutEligibility::class)->inspect($policy, $event->id);
        if ($proposal->amount > $snapshot['available']) {
            $this->refuse('The event available balance changed.');
        }

        return EventPayout::withoutGlobalScopes()->create(['tenant_id' => $policy->tenant_id, 'event_id' => $event->id, 'category' => LedgerCategoryEnum::Event,
            'payout_account_id' => $policy->payout_account_id, 'amount' => $proposal->amount, 'currency' => 'GHS', 'status' => 'scheduled', 'scheduled_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function sendEvent(EventPayout $payout): array
    {
        $claim = $payout->getConnection()->transaction(function () use ($payout): EventPayout {
            $locked = EventPayout::withoutGlobalScopes()->lockForUpdate()->findOrFail($payout->id);
            if ($locked->status !== 'scheduled' || $locked->provider_reference !== null) {
                $this->refuse('This payout was already submitted.');
            }
            $locked->update(['status' => 'processing', 'provider_reference' => 'scheduled-payout-'.Str::uuid()]);

            return $locked;
        });

        return app(PayoutSender::class)->send($claim, 'GHS', $claim->provider_reference);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['schedule' => $message]);
    }
}

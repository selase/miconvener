<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\EventPayout;
use App\Models\OrganisationPayoutPolicy;
use App\Models\ScheduledPayoutProposal;
use App\Models\User;
use App\Services\Marketplace\CommerceInventoryLocks;
use Closure;
use Illuminate\Validation\ValidationException;

final class PayoutSubmissionGuard
{
    /** @var array<string,User> */
    private array $actors = [];

    /** @return array<string,mixed> */
    public function withHuman(EventPayout $payout, User $actor, Closure $action): array
    {
        $this->actors[$payout->id] = $actor;
        try {
            return $action();
        } finally {
            unset($this->actors[$payout->id]);
        }
    }

    public function assertEntry(EventPayout $payout): void
    {
        if (ScheduledPayoutProposal::query()->where('payout_id', $payout->id)->exists() && ! isset($this->actors[$payout->id])) {
            $this->refuse('Use the scheduled payout review screen for this payout.');
        }
    }

    public function claim(EventPayout $payout): void
    {
        $this->assertEntry($payout);
        $proposal = ScheduledPayoutProposal::query()->where('payout_id', $payout->id)->first();
        if ($proposal !== null) {
            $payout->getConnection()->transaction(function () use ($proposal, $payout): void {
                $tenant = app(CommerceInventoryLocks::class)->tenant($payout->tenant_id);
                $current = ScheduledPayoutProposal::query()->lockForUpdate()->findOrFail($proposal->id);
                $policy = OrganisationPayoutPolicy::query()->where('tenant_id', $tenant->id)->findOrFail($current->policy_id);
                $policies = app(PayoutPolicies::class);
                $policies->authoriseTenant($tenant, $this->actors[$payout->id]);
                $policies->authoriseTenant($tenant, $this->actors[$payout->id], $policy->category === 'commerce' ? 'manage store finance' : 'manage payouts');
                $policies->assertActive($policy);
                app(PayoutReconciliationCheckpoints::class)->assertFresh($policy->account_fingerprint, $tenant->id.':'.$policy->category);
                if ($current->status !== 'approved' || $current->submit_before === null || now()->gte($current->submit_before)
                    || $payout->amount !== $current->amount || $payout->payout_account_id !== $policy->payout_account_id || $payout->currency !== 'GHS') {
                    $this->refuse('This payout approval changed or expired. No transfer was authorised.');
                }
                if ($current->event_id !== null) {
                    \App\Models\Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($current->event_id);
                } elseif (in_array($policy->category, ['dues', 'giving'], true)) {
                    app(CommunityPayouts::class)->balance(\App\Enum\LedgerCategoryEnum::from($policy->category))->lockPayableAccount($tenant, 'GHS');
                }
                $available = app(PayoutEligibility::class)->inspect($policy, $current->event_id, $payout->id);
                if ($payout->amount > $available['available'] || $available['fee'] !== $payout->requested_transfer_fee_amount || $available['terms_fingerprint'] !== $current->snapshot['terms_fingerprint']) {
                    $this->refuse('The available funds or transfer fee changed before submission.');
                }
                app(ScheduledPayoutApproval::class)->assertLimits($policy, $current);
                app(ProviderLiquidity::class)->claim($payout);
                $current->update(['status' => 'submitted']);
                $policies->record($policy, $this->actors[$payout->id], 'payout_submitted', ['payout_id' => $payout->id, 'proposal_id' => $current->id, 'provider_reference' => $payout->provider_reference, 'account_fingerprint' => $policy->account_fingerprint, 'recipient_code' => $payout->payoutAccount?->recipient_code, 'net' => $payout->requested_net_amount, 'fee' => $payout->requested_transfer_fee_amount]);
            });
        } elseif (config('services.payout_schedules.liquidity_controls') === true) {
            app(ProviderLiquidity::class)->refresh(app(PayoutPolicies::class)->accountFingerprint(), $payout->currency, $payout->id);
            app(ProviderLiquidity::class)->claim($payout);
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['payout' => $message]);
    }
}

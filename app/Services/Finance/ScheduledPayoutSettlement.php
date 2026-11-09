<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Contracts\SettlementGateway;
use App\Models\Event;
use App\Models\EventPayout;
use App\Models\OrganisationPayoutPolicy;
use App\Models\PayoutPolicyAction;
use App\Models\ScheduledPayoutProposal;
use App\Services\Marketplace\CommerceInventoryLocks;
use LogicException;

final class ScheduledPayoutSettlement
{
    public function reconcile(EventPayout $payout): void
    {
        $proposal = ScheduledPayoutProposal::query()->where('payout_id', $payout->id)->firstOrFail();
        $policy = OrganisationPayoutPolicy::query()->findOrFail($proposal->policy_id);
        $intent = PayoutPolicyAction::query()->where('policy_id', $policy->id)->where('action', 'payout_submitted')
            ->where('evidence->payout_id', $payout->id)->latest('created_at')->first()?->evidence;
        if ($intent === null || ! hash_equals($policy->account_fingerprint, app(PayoutPolicies::class)->accountFingerprint())) {
            $this->review($payout, 'Original transfer intent or settlement account needs review.');

            return;
        }
        $verified = app(SettlementGateway::class)->verifyTransfer((string) $payout->provider_reference);
        if (($verified['reference'] ?? null) !== $intent['provider_reference'] || ($verified['amount'] ?? null) !== $intent['net']
            || ($verified['currency'] ?? null) !== 'GHS' || ($verified['recipient_code'] ?? null) !== $intent['recipient_code']
            || (in_array($verified['status'], ['success', 'reversed'], true)
                && (! is_int($verified['fee']) || $verified['fee'] !== $intent['fee']))
            || (in_array($verified['status'], ['failed', 'abandoned'], true) && ($verified['fee']) !== 0)) {
            $this->review($payout, 'Provider evidence differs from the original amount, fee, currency, reference or destination.');

            return;
        }
        $payout->getConnection()->transaction(function () use ($payout, $proposal, $policy, $verified): void {
            $tenant = app(CommerceInventoryLocks::class)->tenant($payout->tenant_id);
            $currentProposal = ScheduledPayoutProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $locked = EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($payout->id);
            if ($locked->provider_reference !== $verified['reference']) {
                return;
            }
            $ledger = app(LedgerService::class);
            $status = $verified['status'];
            if ($status === 'otp' && in_array($locked->status, ['processing', 'awaiting_otp'], true) && filled($verified['transfer_code'] ?? null)) {
                $locked->update(['status' => 'awaiting_otp', 'provider_transfer_code' => $verified['transfer_code'], 'failure_reason' => null]);
            }
            if (in_array($status, ['success', 'reversed'], true) && in_array($locked->status, ['processing', 'awaiting_otp'], true)) {
                $locked->update(['status' => 'paid', 'paid_at' => now(), 'transfer_fee_amount' => $verified['fee'], 'net_paid_amount' => $verified['amount'], 'failure_reason' => null]);
                match ($policy->category) {
                    'commerce' => $ledger->recordStorePayout($locked, $locked->provider_reference),
                    'dues' => $ledger->recordDuesPayout($tenant, $locked->amount, $locked->provider_reference, $locked, 'GHS', $verified['fee']),
                    'giving' => $ledger->recordGivingPayout($tenant, $locked, $locked->provider_reference, $verified['fee']),
                    'event' => $ledger->recordPayout(Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($locked->event_id), $locked->amount, $locked->provider_reference, $locked, $verified['fee']),
                    default => throw new LogicException('Unsupported payout accounting category.'),
                };
                $currentProposal->update(['status' => 'paid', 'reason' => null]);
                if ($status === 'success') {
                    app(ScheduledPayoutNotices::class)->enqueue($policy, 'payout_confirmed', $currentProposal->id);
                }
            }
            if ($status === 'reversed' && $locked->status === 'paid') {
                $ledger->recordScheduledPayoutReversal($locked, $verified['amount']);
                $locked->update(['status' => 'reversed', 'reversed_amount' => $verified['amount'], 'reversed_at' => now(),
                    'failure_reason' => 'Provider reversed the recipient transfer. Transfer fees remain charged unless separately confirmed returned.']);
                $currentProposal->update(['status' => 'reversed', 'reason' => 'Returned principal restored; the original fee remains consumed.']);
            } elseif (in_array($status, PayoutRelease::DEAD_TRANSFER_STATUSES, true) && in_array($locked->status, ['processing', 'awaiting_otp'], true)) {
                $locked->update(['status' => 'failed', 'failure_reason' => 'Original transfer conclusively '.$status.'. Review before creating any replacement.']);
                $currentProposal->update(['status' => 'failed', 'reason' => 'Original transfer '.$status.'. No automatic retry.']);
            }
            $currentProposal->touch();
        });
    }

    private function review(EventPayout $payout, string $reason): void
    {
        $proposal = ScheduledPayoutProposal::query()->where('payout_id', $payout->id)->firstOrFail();
        $policy = OrganisationPayoutPolicy::query()->findOrFail($proposal->policy_id);
        app(PayoutReconciliationCheckpoints::class)->block($policy->account_fingerprint, 'Transfer evidence needs finance review.');
        app(ScheduledPayoutNotices::class)->enqueue($policy, 'review_required', $payout->id);
        EventPayout::withoutGlobalScopes()->whereKey($payout->id)->where('provider_reference', $payout->provider_reference)
            ->update(['failure_reason' => $reason]);
    }
}

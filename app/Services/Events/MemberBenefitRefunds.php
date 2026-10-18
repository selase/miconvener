<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventMemberBenefitAttempt;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Authorised staff actions only; an uncertain request is reconciled, never resent. */
final class MemberBenefitRefunds
{
    public function __construct(private readonly MemberBenefitGateways $gateways, private readonly LedgerService $ledger) {}

    public function request(EventMemberBenefitAttempt $candidate): EventMemberBenefitAttempt
    {
        if (in_array($candidate->fresh()->state, ['refund_pending', 'refund_unknown', 'refunded'], true)) {
            return $candidate->fresh();
        }
        $releasedTicket = ! $candidate->is_extra_capture && $candidate->registration->isConfirmed();
        $gateway = $this->gateways->forAttempt($candidate);
        [$attempt, $new] = $candidate->getConnection()->transaction(function () use ($candidate): array {
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->registration_id);
            Event::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($registration->event_id);
            $attempt = EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            if (in_array($attempt->state, ['refund_pending', 'refund_unknown', 'refunded'], true)) {
                return [$attempt, false];
            }
            if (! in_array($attempt->state, ['paid_review', 'paid_extra', 'fulfilled'], true) || $attempt->booked_at === null || $attempt->provider_reference === null || $attempt->gateway_fee_amount === null) {
                throw ValidationException::withMessages(['refund' => 'Reconcile the original captured payment and its fee before refunding.']);
            }
            $attempt->update(['refund_operation_key' => (string) Str::uuid(), 'state' => 'refund_pending', 'review_reason' => 'refund_requested']);
            if (! $attempt->is_extra_capture) {
                $registration->update(['status' => EventRegistration::STATUS_CANCELLED]);
            }

            return [$attempt, true];
        });
        if (! $new) {
            return $attempt;
        }
        try {
            $result = $gateway->requestGivingRefund($attempt->provider_reference, $attempt->charged_amount, $attempt->refund_operation_key);
            $reference = (string) ($result['reference']);
            EventMemberBenefitAttempt::withoutGlobalScopes()->whereKey($attempt->id)->where('refund_operation_key', $attempt->refund_operation_key)->where('state', 'refund_pending')
                ->update(['refund_reference' => $reference !== '' ? $reference : null, 'state' => $reference !== '' ? 'refund_pending' : 'refund_unknown', 'review_reason' => $reference !== '' ? 'refund_awaiting_confirmation' : 'refund_unknown']);
        } catch (Throwable) {
            EventMemberBenefitAttempt::withoutGlobalScopes()->whereKey($attempt->id)->where('refund_operation_key', $attempt->refund_operation_key)->where('state', 'refund_pending')
                ->update(['state' => 'refund_unknown', 'review_reason' => 'refund_unknown']);
        }

        if ($releasedTicket) {
            $event = Event::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->findOrFail($candidate->registration->event_id);
            if (! $event->trashed()) {
                app(MemberBenefitLifecycle::class)->promoteWaiting($event, $candidate->registration->ticket_type_id);
            }
        }

        return $attempt->fresh();
    }

    public function reconcile(EventMemberBenefitAttempt $candidate, ?string $providerRefundId = null): EventMemberBenefitAttempt
    {
        $candidate->refresh();
        if ($candidate->state === 'refunded') {
            return $candidate;
        }
        if (! in_array($candidate->state, ['refund_pending', 'refund_unknown'], true)) {
            throw ValidationException::withMessages(['refund' => 'This payment has no unresolved refund.']);
        }
        if ($providerRefundId !== null && $candidate->refund_reference === null && mb_strlen($providerRefundId) <= 255) {
            $candidate->refund_reference = $providerRefundId;
        }
        if (! filled($candidate->refund_reference)) {
            return $candidate;
        }
        $data = $this->gateways->verifyRefund($candidate);
        if (! in_array($data['status'] ?? '', ['processed', 'succeeded'], true) || ($data['amount'] ?? null) !== $candidate->charged_amount || ($data['currency'] ?? null) !== $candidate->currency) {
            return $candidate->fresh();
        }

        return $candidate->getConnection()->transaction(function () use ($candidate, $data): EventMemberBenefitAttempt {
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->registration_id);
            Event::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($registration->event_id);
            $attempt = EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            if ($attempt->state === 'refunded' || $attempt->refund_operation_key !== $candidate->refund_operation_key) {
                return $attempt;
            }
            $returned = (int) ($data['commission_returned_cumulative'] ?? $data['commission_returned'] ?? 0);
            $credit = $data['gateway_credit'] ?? 0;
            $received = (int) ($attempt->commission_settlement['actual_received'] ?? 0);
            if (! is_int($credit) || $credit < 0 || $credit > $attempt->gateway_fee_amount || $returned < 0 || $returned > $received) {
                throw ValidationException::withMessages(['refund' => 'The refund fee outcome needs operator reconciliation.']);
            }
            if (! $attempt->is_extra_capture) {
                $registration->update(['status' => EventRegistration::STATUS_CANCELLED]);
            }
            if ($attempt->provider === 'stripe' && $returned !== $received) {
                $attempt->update(['state' => 'refund_pending', 'review_reason' => 'refund_commission_pending', 'refund_reference' => $candidate->refund_reference]);

                return $attempt;
            }
            if ($attempt->provider === 'stripe' && ! ($data['gateway_credit_reported'] ?? false)) {
                $attempt->update(['state' => 'refund_pending', 'review_reason' => 'refund_fee_pending', 'refund_reference' => $candidate->refund_reference]);

                return $attempt;
            }
            $this->ledger->recordMemberBenefitRefund($attempt, $credit, $returned);
            if ($attempt->settlement_mode !== Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
                \App\Models\MerchantTransaction::withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->where('provider', $attempt->provider)
                    ->where('provider_transaction_id', $attempt->provider_reference)->where('type', 'payment')->update(['status' => 'refunded']);
                \App\Models\MerchantTransaction::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $attempt->tenant_id, 'provider' => $attempt->provider, 'provider_transaction_id' => 'member_refund_'.$attempt->id],
                    ['amount' => $attempt->charged_amount, 'currency' => $attempt->currency, 'status' => 'succeeded', 'type' => 'refund',
                        'customer_email' => $registration->email, 'description' => 'Member ticket refund',
                        'meta' => ['member_benefit_attempt_id' => $attempt->id, 'refund_id' => $candidate->refund_reference]]);
            }

            $attempt->update(['state' => 'refunded', 'refunded_at' => now(), 'review_reason' => null, 'refund_reference' => $candidate->refund_reference,
                'refund_gateway_credit' => $credit, 'refund_commission_returned' => $returned]);

            return $attempt;
        });
    }
}

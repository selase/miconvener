<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\Event;
use App\Models\EventMemberBenefitAttempt;
use App\Models\EventMemberBenefitQuote;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use App\Services\Notifications\EventRuleTriggerService;
use App\Services\Tenancy\FeatureMeteringService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** Consumes only an original-account API lookup or a verified provider notification. */
final class MemberBenefitPayments
{
    public function __construct(private readonly MemberBenefitIdentity $identity, private readonly MemberBenefitEligibility $eligibility, private readonly MemberBenefitQuotes $quotes, private readonly LedgerService $ledger) {}

    /** @param array<string, mixed> $verifiedPayment */
    public function confirm(EventMemberBenefitAttempt $candidate, array $verifiedPayment): EventRegistration
    {
        try {
            return $candidate->getConnection()->transaction(function () use ($candidate, $verifiedPayment): EventRegistration {
                $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->registration_id);
                $event = Event::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($registration->event_id);
                $attempt = EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
                $quote = EventMemberBenefitQuote::withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->findOrFail($attempt->quote_id);
                $this->assertPayment($attempt, $quote, $registration, $verifiedPayment);
                if (in_array($attempt->state, ['fulfilled', 'refunded', 'refund_pending', 'refund_unknown'], true)) {
                    return $registration;
                }
                if (($verifiedPayment['status'] ?? null) !== 'success') {
                    return $registration;
                }
                $fee = $verifiedPayment['gateway_fee'] ?? null;
                $paidAt = $verifiedPayment['paid_at'] ?? null;
                if ($paidAt !== null && ! $paidAt instanceof CarbonImmutable) {
                    $this->refuse('Payment completion time must come from the verified provider adapter.');
                }
                if ($attempt->paid_at !== null && $paidAt !== null && $attempt->paid_at->timestamp !== $paidAt->timestamp) {
                    $this->refuse('The captured payment timestamp changed and requires operator review.');
                }
                $attempt->fill(['paid_at' => $paidAt ?? $attempt->paid_at, 'provider_reference' => $verifiedPayment['provider_reference'] ?? $attempt->reference]);
                $extra = $attempt->is_extra_capture || $quote->id !== $registration->member_benefit_quote_id
                    || ($registration->isConfirmed() && $registration->payment_reference !== $attempt->provider_reference)
                    || EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->where('registration_id', $registration->id)
                        ->whereKeyNot($attempt->id)->whereIn('state', ['preparing', 'open', 'unknown'])->exists();
                if ($fee === null) {
                    $attempt->fill(['state' => $extra ? 'paid_extra' : 'paid_review', 'is_extra_capture' => $extra, 'review_reason' => 'gateway_fee_pending'])->save();
                    if (! $extra && ! in_array($registration->status, [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED], true)) {
                        $registration->update(['status' => EventRegistration::STATUS_PENDING_BENEFIT_REVIEW]);
                    }

                    return $registration;
                }
                if (! is_int($fee) || $fee < 0 || $fee >= $attempt->charged_amount || ($attempt->gateway_fee_amount !== null && $attempt->gateway_fee_amount !== $fee)) {
                    $this->refuse('The captured payment fee requires operator review.');
                }
                $attempt->gateway_fee_amount = $fee;
                $attempt->save();
                if ($extra) {
                    $book = clone $registration;
                    $book->fill(['amount' => $quote->amount, 'charged_amount' => $quote->charged_amount, 'platform_fee_amount' => $quote->platform_fee_amount,
                        'gateway_fee_amount' => $fee, 'payment_reference' => $attempt->provider_reference, 'currency' => $quote->currency]);
                    $this->recordSale($event, $book, $attempt, $verifiedPayment);
                    $attempt->update(['is_extra_capture' => true, 'state' => 'paid_extra', 'review_reason' => 'additional_capture']);

                    return $registration;
                }
                $registration->fill(['amount' => $quote->amount, 'charged_amount' => $quote->charged_amount, 'platform_fee_amount' => $quote->platform_fee_amount,
                    'gateway_fee_amount' => $fee, 'payment_reference' => $attempt->provider_reference, 'currency' => $quote->currency,
                    'payment_method' => $attempt->provider]);
                $this->recordSale($event, $registration, $attempt, $verifiedPayment);
                $member = Member::withoutGlobalScopes()->where('tenant_id', $quote->tenant_id)->lockForUpdate()->find($quote->member_id);
                $reason = match (true) {
                    $member === null || ! $this->identity->valid($quote->identity, $member, false) => 'identity_changed',
                    $paidAt === null && $attempt->paid_at === null => 'capture_time_unknown',
                    ($paidAt ?? $attempt->paid_at)->lessThan($quote->created_at->startOfSecond()) => 'capture_time_invalid',
                    ($paidAt ?? $attempt->paid_at)->greaterThanOrEqualTo($quote->expires_at) => 'capture_late',
                    ! hash_equals($quote->cart_fingerprint, $this->quotes->fingerprint($registration)) => 'registration_changed',
                    default => $this->fulfilmentReason($event, $registration),
                };
                if ($reason !== null) {
                    $attempt->update(['state' => 'paid_review', 'review_reason' => $reason]);
                    if (! in_array($registration->status, [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED], true)) {
                        $registration->status = EventRegistration::STATUS_PENDING_BENEFIT_REVIEW;
                    }
                    $registration->save();

                    return $registration;
                }
                $this->fulfil($event, $registration, $attempt);

                return $registration;
            });
        } catch (ValidationException $exception) {
            if (($verifiedPayment['provider'] ?? null) === $candidate->provider && ($verifiedPayment['account_key'] ?? null) === $candidate->account_key
                && ($verifiedPayment['reference'] ?? null) === $candidate->reference && ($verifiedPayment['status'] ?? null) === 'success') {
                $evidence = array_intersect_key($verifiedPayment, array_flip(['provider', 'reference', 'provider_reference', 'amount', 'currency', 'gateway_fee']));
                EventMemberBenefitAttempt::withoutGlobalScopes()->whereKey($candidate->id)->where('tenant_id', $candidate->tenant_id)
                    ->whereIn('state', ['preparing', 'open', 'unknown'])->update(['state' => 'unknown', 'review_reason' => 'provider_evidence_mismatch', 'observed_payment' => json_encode($evidence, JSON_THROW_ON_ERROR)]);
            }
            throw $exception;
        }
    }

    public function reassess(EventRegistration $candidate, Session $recipientSession): bool
    {
        return $candidate->getConnection()->transaction(function () use ($candidate, $recipientSession): bool {
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            $event = Event::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->lockForUpdate()->findOrFail($registration->event_id);
            $attempt = EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->where('registration_id', $registration->id)->whereIn('state', ['paid_review', 'fulfilled'])->lockForUpdate()->first();
            if ($attempt === null) {
                return false;
            }
            $member = $this->eligibility->resolveExisting($recipientSession, $event, $registration->email);
            if ($member === null) {
                return false;
            }
            if ($attempt->state === 'fulfilled') {
                return true;
            }
            if ($attempt->gateway_fee_amount === null || $this->fulfilmentReason($event, $registration) !== null) {
                return false;
            }
            $registration->member_benefit_identity = $this->identity->capture($recipientSession, $member);
            $this->fulfil($event, $registration, $attempt);

            return true;
        });
    }

    /** @param array<string, mixed> $data */
    private function assertPayment(EventMemberBenefitAttempt $attempt, EventMemberBenefitQuote $quote, EventRegistration $registration, array $data): void
    {
        if (! $registration->member_benefit_required || $quote->registration_id !== $registration->id || $attempt->registration_id !== $registration->id
            || $quote->event_id !== $registration->event_id
            || ($data['reference'] ?? null) !== $attempt->reference || ($data['provider'] ?? null) !== $attempt->provider
            || ($data['account_key'] ?? null) !== $attempt->account_key || ($data['currency'] ?? null) !== $attempt->currency
            || ($attempt->provider_reference !== null && ($data['provider_reference'] ?? null) !== $attempt->provider_reference)
            || ($data['amount'] ?? null) !== $attempt->charged_amount || $attempt->charged_amount !== $quote->charged_amount
            || $attempt->amount !== $quote->amount || $attempt->platform_fee_amount !== $quote->platform_fee_amount) {
            $this->refuse('The payment does not match its original member checkout. Operator reconciliation is required.');
        }
    }

    /** @param array<string, mixed> $data */
    private function recordSale(Event $event, EventRegistration $registration, EventMemberBenefitAttempt $attempt, array $data): void
    {
        if ($attempt->booked_at !== null) {
            return;
        }
        $frozen = clone $event;
        $snapshot = $attempt->quote->pricing;
        $frozen->platform_fee_percentage = $snapshot['fees']['percentage'];
        $frozen->fee_bearer = $snapshot['fees']['bearer'];
        $frozen->currency = $attempt->currency;
        if ($attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
            $this->ledger->recordTicketSale($frozen, $registration, $attempt->charged_amount, $attempt->platform_fee_amount, $attempt->reference, (int) $attempt->gateway_fee_amount, $attempt->provider, $attempt->provider_reference);
        } else {
            $snapshot = $attempt->commission_settlement ?? [];
            $received = min($attempt->platform_fee_amount, max(0, (int) ($data['commission_received'] ?? 0)));
            $snapshot['commission_collected'] = $received;
            $settlement = \App\Http\Controllers\Tenant\Commerce\WebhookController::commissionSettlement($event->tenant, $attempt->currency,
                $attempt->provider, $snapshot, $attempt->platform_fee_amount, $received);
            $this->ledger->recordOwnGatewayTicketSale($frozen, $registration, $attempt->charged_amount, $attempt->platform_fee_amount, $attempt->reference,
                (int) $attempt->gateway_fee_amount, $attempt->provider, $settlement['received'], $settlement['credit'], $attempt->provider_reference);
            $attempt->commission_settlement = [...$snapshot, 'actual_received' => $settlement['received'], 'actual_credit_used' => $settlement['credit']];
        }
        if ($attempt->settlement_mode !== Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
            \App\Models\MerchantTransaction::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $attempt->tenant_id, 'provider' => $attempt->provider, 'provider_transaction_id' => $attempt->provider_reference],
                ['amount' => $attempt->charged_amount, 'currency' => $attempt->currency, 'status' => 'succeeded', 'type' => 'payment',
                    'customer_email' => $registration->email, 'customer_name' => $registration->full_name, 'description' => 'Member event ticket',
                    'meta' => ['member_benefit_attempt_id' => $attempt->id, 'event_registration_id' => $registration->id]]);
        }
        $attempt->booked_at = now();
        $attempt->save();
    }

    private function fulfilmentReason(Event $event, EventRegistration $registration): ?string
    {
        if ($event->trashed() || ! $event->isPublished() || in_array($registration->status, [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED, EventRegistration::STATUS_PENDING_APPROVAL, EventRegistration::STATUS_WAITLISTED], true)) {
            return 'event_unavailable';
        }
        $type = $registration->ticketType;
        if ($type !== null && (! $type->is_active || $type->event_id !== $event->id || $type->tenant_id !== $event->tenant_id)) {
            return 'ticket_unavailable';
        }
        $confirmed = $event->registrations()->confirmed()->where('id', '!=', $registration->id);
        if ($type !== null) {
            $confirmed->where('ticket_type_id', $type->id);
        }
        $capacity = $type?->capacity ?? $event->capacity;

        return $capacity !== null && $confirmed->count() >= $capacity ? 'capacity_unavailable' : null;
    }

    private function fulfil(Event $event, EventRegistration $registration, EventMemberBenefitAttempt $attempt): void
    {
        $registration->email_verified_at ??= now();
        $registration->status = EventRegistration::STATUS_CONFIRMED;
        $registration->issueTicket();
        $registration->save();
        $attempt->update(['state' => 'fulfilled', 'review_reason' => null, 'fulfilled_at' => now()]);
        $attempt->quote->update(['state' => 'consumed']);
        app(FeatureMeteringService::class)->recordUsage($event->tenant, 'event_registrations');
        app(FeatureMeteringService::class)->recordUsage($event->tenant, 'email_credits');
        app(EventRuleTriggerService::class)->registrationCompleted($registration);
        Mail::to($registration->email)->queue(new EventRegistrationConfirmed($registration));
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}

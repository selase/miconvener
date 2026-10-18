<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Models\Member;
use App\Services\Community\MemberIdentity;
use Illuminate\Validation\ValidationException;

final class MemberBenefitLifecycle
{
    public function __construct(private readonly MemberBenefitEligibility $eligibility, private readonly MemberBenefitIdentity $identity) {}

    /** Called inside registration/event transactions before issuing a new benefit ticket. */
    public function eligible(EventRegistration $registration): bool
    {
        if (! $registration->member_benefit_required) {
            return true;
        }
        $event = $registration->event;
        if (! $this->eligibility->enabled($event->tenant) || $event->trashed() || ! $event->isPublished()) {
            return false;
        }
        $proof = $registration->member_benefit_identity ?? [];
        $member = Member::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->lockForUpdate()->find($proof['member_id'] ?? null);

        return $member !== null && $member->status === \App\Enum\MemberStatusEnum::Active && $this->identity->valid($proof, $member)
            && app(MemberIdentity::class)->normaliseEmail($registration->email) === app(MemberIdentity::class)->normaliseEmail($member->email);
    }

    public function priceMatches(EventRegistration $registration): bool
    {
        if (! $registration->member_benefit_required) {
            return true;
        }
        try {
            $pricing = app(MemberBenefitQuotes::class)->pricing($registration);

            return $registration->amount === $pricing['amount'] && $registration->effectiveChargedAmount() === $pricing['fees']['charged_amount'];
        } catch (ValidationException) {
            return false;
        }
    }

    public function sharesInventory(\App\Models\Event $event): bool
    {
        return $this->eligibility->enabled($event->tenant) || $event->registrations()->where('member_benefit_required', true)->exists();
    }

    public function hasCapacity(EventRegistration $registration): bool
    {
        $event = $registration->event;
        $type = $registration->ticketType;
        if ($event->trashed() || ! $event->isPublished() || ($type !== null && ! $type->is_active)) {
            return false;
        }
        $count = $event->registrations()->confirmed()->where('id', '!=', $registration->id);
        if ($type !== null) {
            return $type->capacity === null || $count->where('ticket_type_id', $type->id)->count() < $type->capacity;
        }

        return $event->capacity === null || $count->count() < $event->capacity;
    }

    public function reverify(EventRegistration $candidate, \Illuminate\Contracts\Session\Session $session, ?int $expectedAmount = null, ?string $currency = null): bool
    {
        return $candidate->getConnection()->transaction(function () use ($candidate, $session, $expectedAmount, $currency): bool {
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            $event = $registration->event()->lockForUpdate()->firstOrFail();
            $member = $this->eligibility->resolveLocked($session, $event, $registration->email);
            if (! $registration->member_benefit_required || $member === null) {
                throw ValidationException::withMessages(['membership' => 'Verify your own active membership before continuing.']);
            }
            $reason = $registration->member_benefit_identity['release_reason'] ?? null;
            $registration->member_benefit_identity = $this->identity->capture($session, $member);
            $this->assertEligible($registration);
            $registration->save();
            if ($expectedAmount !== null && $registration->status === EventRegistration::STATUS_PENDING_PAYMENT) {
                $this->assertOfflineAvailable($registration);
                if ($registration->offline_payment_status !== null && $registration->offline_payment_status !== EventRegistration::OFFLINE_STATUS_REJECTED) {
                    throw ValidationException::withMessages(['payment' => 'Existing offline payment evidence needs review before changing the payable total.']);
                }
                $pricing = app(MemberBenefitQuotes::class)->pricing($registration);
                if ($expectedAmount !== $pricing['fees']['charged_amount'] || $currency !== $registration->currency) {
                    throw ValidationException::withMessages(['payment' => 'The total changed. Review and accept the current total again.']);
                }
                $registration->memberBenefitQuotes()->where('state', 'quoted')->update(['state' => 'invalidated']);
                $registration->fill(['member_benefit_quote_id' => null, 'amount' => $pricing['amount'], 'discount_amount' => $pricing['discount_amount'], 'charged_amount' => $pricing['fees']['charged_amount'], 'platform_fee_amount' => $pricing['fees']['platform_fee']])->save();
                if ($registration->amount === 0) {
                    $this->release($registration, 'repriced');
                }
            }
            if ($registration->status === EventRegistration::STATUS_PENDING_MEMBER_VERIFICATION && in_array($reason, ['verified', 'approved', 'promoted'], true)) {
                $pricing = app(MemberBenefitQuotes::class)->pricing($registration);
                $registration->fill(['amount' => $pricing['amount'], 'discount_amount' => $pricing['discount_amount'], 'charged_amount' => $pricing['fees']['charged_amount'], 'platform_fee_amount' => $pricing['fees']['platform_fee']]);
                if (! $this->hasCapacity($registration)) {
                    $this->hold($registration, $reason);

                    return false;
                }
                $this->release($registration, $reason);
            }

            return $registration->isConfirmed();
        });
    }

    public function release(EventRegistration $registration, string $reason): void
    {
        if (! $registration->member_benefit_required && $this->sharesInventory($registration->event) && ! $this->hasCapacity($registration)) {
            return;
        }
        if (! $this->eligible($registration) || ($registration->member_benefit_required && (! $this->hasCapacity($registration) || ! $this->priceMatches($registration)))) {
            $this->hold($registration, $reason);
            \Illuminate\Support\Facades\Mail::to($registration->email)->queue((new \App\Mail\Events\EventRegistrationVerifyEmail($registration))->afterCommit());

            return;
        }
        if ($registration->amount === 0) {
            $registration->fill(['status' => EventRegistration::STATUS_CONFIRMED, 'waitlist_position' => null]);
            $registration->email_verified_at ??= now();
            $registration->issueTicket();
            $registration->save();
            if ($registration->member_benefit_required && $reason !== 'verified') {
                app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($registration->event->tenant, 'event_registrations');
            }
            app(\App\Services\Notifications\EventRuleTriggerService::class)->registrationCompleted($registration);
            \Illuminate\Support\Facades\Mail::to($registration->email)->queue((new \App\Mail\Events\EventRegistrationConfirmed($registration))->afterCommit());

            return;
        }
        $registration->update(['status' => EventRegistration::STATUS_PENDING_PAYMENT, 'waitlist_position' => null]);
        \Illuminate\Support\Facades\Mail::to($registration->email)->queue((new \App\Mail\Events\EventRegistrationPaymentInvite($registration, $reason))->afterCommit());
    }

    public function promoteWaiting(\App\Models\Event $event, ?string $ticketTypeId): void
    {
        $query = fn () => EventRegistration::query()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)
            ->when($ticketTypeId !== null, fn ($query) => $query->where('ticket_type_id', $ticketTypeId), fn ($query) => $query->whereNull('ticket_type_id'));
        $next = $query()->waitlisted()->orderBy('waitlist_position')->first();
        if ($next === null) {
            return;
        }
        $next->getConnection()->transaction(function () use ($next, $event, $query): void {
            $next = EventRegistration::query()->lockForUpdate()->findOrFail($next->id);
            $lockedEvent = $event->newQuery()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($lockedEvent->status === \App\Models\Event::STATUS_CANCELLED || $lockedEvent->trashed()) {
                return;
            }
            if ($next->status !== EventRegistration::STATUS_WAITLISTED) {
                return;
            }
            $position = $next->waitlist_position;
            $this->release($next, 'promoted');
            if ($next->status !== EventRegistration::STATUS_WAITLISTED) {
                $query()->waitlisted()->where('waitlist_position', '>', $position)->decrement('waitlist_position');
            }
        });
    }

    public function assertOfflineAvailable(EventRegistration $registration): void
    {
        if ($registration->member_benefit_required && \App\Models\EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)
            ->where('registration_id', $registration->id)->whereNotIn('state', ['failed', 'refunded'])->exists()) {
            throw ValidationException::withMessages(['payment' => 'An original online payment is unresolved. Reconcile it before recording or approving an offline payment. Do not pay again.']);
        }
    }

    public function assertEligible(EventRegistration $registration): void
    {
        if (! $this->eligible($registration)) {
            throw ValidationException::withMessages(['membership' => 'The attendee must verify their active personal membership again at the event page before you approve this registration.']);
        }
    }

    public function hold(EventRegistration $registration, string $releaseReason): void
    {
        $registration->update(['status' => EventRegistration::STATUS_PENDING_MEMBER_VERIFICATION, 'waitlist_position' => null,
            'member_benefit_identity' => [...($registration->member_benefit_identity ?? []), 'release_reason' => $releaseReason]]);
    }
}
